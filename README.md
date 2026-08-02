# Starter

A Laravel starter for multi-tenant SaaS.

Teams, roles, invitations, passkeys and two-factor sign-in are already built. Tenancy is enforced by the
URL, so any route placed inside the team group is scoped to that team without a controller doing anything.

Observability is wired end to end. Traces, metrics, logs, browser errors and session replay all land in
one self-hosted store, and a JavaScript error carries the trace id of the request that caused it.

Development runs the drivers production runs: mail over SMTP to a local inbox, files over the S3 driver
to a local object store. The application refuses to boot in production while still pointed at either.

## Run

```bash
mise bootstrap   # toolchain, dependencies, containers, migrate, seed, build
mise dev         # octane (hot reload) + vite + reverb + queue worker
```

|                |                         |
| -------------- | ----------------------- |
| App            | <http://localhost:8000> |
| Mail inbox     | <http://localhost:8025> |
| Object browser | <http://localhost:8888> |
| Telemetry      | <http://localhost:5080> |

Sign in as `test@example.com` / `Password1!`; OpenObserve as `root@example.com` / `Password1!`.

Dev runs real SMTP (Mailpit) and real S3 (SeaweedFS), the same drivers as production.

## Layout

|                                                |                                 |
| ---------------------------------------------- | ------------------------------- |
| `routes/web.php`                               | Tenant-scoped routes            |
| `routes/settings.php`                          | Profile, security, teams        |
| `app/Http/Middleware/EnsureTeamMembership.php` | Tenancy + role gate             |
| `app/Concerns/HasTeams.php`                    | Team API on `User`              |
| `app/Enums/TeamRole.php`, `TeamPermission.php` | Authorization                   |
| `app/Actions/Fortify/`                         | Registration and password hooks |
| `resources/js/pages/`                          | Inertia pages                   |

## Adding a tenant-scoped feature

The team slug is a route prefix, so scoping is structural. Add the route inside the existing group and
it is tenant-scoped and members-only:

```php
Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('projects', ProjectController::class)->name('projects.index');
        // → /{team-slug}/projects
    });
```

`SetTeamUrlDefaults` fills the slug in, so `route('projects.index')` takes no arguments.
`EnsureTeamMembership` 403s non-members and switches the user's current team when they navigate to
another team's URL.

Gate by role with a middleware parameter:

```php
Route::delete('projects/{project}', ...)->middleware(EnsureTeamMembership::class.':admin');
```

Check permissions rather than roles:

```php
$user->hasTeamPermission($team, TeamPermission::RemoveMember);
```

| Role   | Permissions                                                                                 |
| ------ | ------------------------------------------------------------------------------------------- |
| Owner  | all nine                                                                                    |
| Admin  | `team:update`, `invitation:create`, `invitation:cancel`, `api_key:manage`, `webhook:manage` |
| Member | none                                                                                        |

New permission: add a `TeamPermission` case, map it in `TeamRole::permissions()`. The frontend
receives the resolved set as `TeamPermissions`, so buttons hide themselves.

Also on `User`: `belongsToTeam`, `ownsTeam`, `teamRole`, `switchTeam`, `personalTeam`, `fallbackTeam`.

## Built in

- **Auth** (Fortify, headless): registration, login, password reset, email verification, passkeys,
  TOTP 2FA with recovery codes, password confirmation
- **Teams**: personal team on registration, invitations by email, role management
- **Observability**: the browser shares a trace id with the backend, so a JavaScript error and the SQL
  query behind it are one trace. Traces, metrics, logs, browser errors, Core Web Vitals and session
  replay all land in OpenObserve

Source maps upload separately so they never ship to browsers: `mise build && php artisan rum:sourcemaps`.

## Public API

`routes/api.php` is versioned by prefix. Everything under it is authenticated by an API key and scoped to
the team that key belongs to, so a controller never filters by team itself.

|                    |                                                |
| ------------------ | ---------------------------------------------- |
| `/api/v1`          | The API                                        |
| `/docs/v1`         | Browsable reference                            |
| `/openapi/v1.json` | The spec, as a static file customers can fetch |

The spec is inferred from controllers and resources by Scramble — there are no annotations to keep in
step. `mise check` regenerates it and fails if the committed copy no longer matches the code, so the
published contract cannot drift from what the API actually returns. After changing a response shape:

```bash
php artisan scramble:export --api=v1 --path=public/openapi/v1.json
```

Keys are owned by the team, not the member who created it, so an integration survives that person
leaving; `created_by` keeps the audit trail. Secrets are shown once, stored only as a SHA-256 hash, and
carry a `sk_live_`/`sk_test_` prefix and a checksum so a leaked key is identifiable by a secret scanner.
Revocation is a timestamp rather than a delete — a key still being sent after rotation shows up in
`last_used_at` instead of vanishing. Errors are RFC 9457 problem details, and every one carries the
`trace_id` of the request that produced it.

What every endpoint gets from the middleware stack, without asking:

|               |                                                                                                                                                                                     |
| ------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Rate limiting | Per key, not per address. `X-RateLimit-*` on every response, `Retry-After` on a 429. Live and test keys have separate budgets — `config/api.php`                                    |
| Pagination    | Cursor, not offset, so a list stays correct while rows are inserted underneath it. An unreadable cursor is a 400 rather than a silent restart                                       |
| Idempotency   | Send `Idempotency-Key` on a POST, PUT or PATCH and a retry replays the first answer instead of acting twice. 422 if reused for a different request, 409 while the first is still running, 400 if sent on a method that is already repeatable |

Idempotency follows `draft-ietf-httpapi-idempotency-key-header`. Records are kept 24 hours and swept
hourly. A 5xx is never recorded, so a transient failure does not pin itself to the key, and a claim left
behind by a killed worker is taken over after five minutes rather than blocking the key until it expires.
One-time secrets are stripped from anything stored for replay.

### Webhooks

Teams register endpoints under **Teams → Developers → Webhooks**, or at `/api/v1/webhook-endpoints`,
and subscribe to event types or to `*` for everything including types added later. Emit one from
anywhere:

```php
Webhooks::send($team, 'member.added', ['id' => $member->id]);
```

Signing follows [Standard Webhooks](https://www.standardwebhooks.com), so customers verify with an
off-the-shelf library rather than hand-written HMAC — which is where verification usually goes wrong or
gets skipped. Each delivery carries `webhook-id`, `webhook-timestamp` and `webhook-signature`, the
signature being HMAC-SHA256 over `id.timestamp.payload`. The id and timestamp are inside the signature,
so neither can be altered in flight and the timestamp is usable as replay protection.

Endpoint URLs must be https and must resolve to a public address, and deliveries do not follow
redirects — a webhook URL is the one destination a customer picks for our servers to call, which makes
it an SSRF primitive otherwise. Delivery is queued and retried at 10s, 1m, 5m, 30m and 2h. Any 2xx counts as accepted. The `webhook-id`
is stable across retries, so a receiver can recognise a redelivery — the same guarantee `Idempotency-Key`
gives in the other direction. Every attempt is recorded in `webhook_deliveries` with the response, which
is the only evidence either side has when deliveries quietly stop arriving. History is kept for
`API_WEBHOOK_RETENTION_DAYS` (30) and swept daily.

## Constraints

Twelve-factor, and things break if you ignore these:

- **Config is environment variables.** No `.env` is committed. Anything that differs between
  environments is a variable.
- **Processes are stateless.** Session, cache and queue live in Postgres; no app container mounts a
  volume. Never write to local disk — use the `s3` disk.
- **Logs are event streams.** stderr plus OTLP, identical in every environment. Nothing writes a log
  file, so there is no rotation to own.
- **One image, built once.** `Dockerfile` bakes vendor, assets and SSR in; config arrives at run time.
  Runs on Laravel Cloud, Fly, Railway, ECS, Kubernetes or plain Docker.

## Shipping

`main` is always releasable, branches are short-lived. CI runs on every PR and push to `main`, and
runs `mise bootstrap` then `mise check` — the same commands you run. mise is the only source of tool
versions, so there is no CI-specific config to keep in step.

`mise preview` runs the production image in the compose cluster locally. It is a preview, not a
deployment — `compose.yml` runs as `APP_ENV=preview`, which is why it may point at Mailpit and
SeaweedFS. Any other environment name is guarded.

## Config

Everything a deployment must set, and nothing it does not. ⚠ marks a value with a working default
committed to this repo, which is what makes it dangerous — skipping it fails nothing. 🛑 is enforced:
the app refuses to boot outside local/preview while still pointed at a development service.

| Variable                | `mise dev`                                            | Set it to                                                       |
| ----------------------- | ----------------------------------------------------- | --------------------------------------------------------------- |
| `APP_KEY`               | ⚠ `base64:g7J5n6…PZLo8Td0=`                           | A generated key — it signs signed URLs and verification links   |
| `APP_URL`               | `http://localhost:8000`                               | The public origin; drives Reverb origins and URL generation     |
| `AWS_ACCESS_KEY_ID`     | ⚠ `starter`                                           | Object storage credential                                       |
| `AWS_SECRET_ACCESS_KEY` | ⚠ `starter`                                           | Object storage credential                                       |
| `AWS_DEFAULT_REGION`    | `us-east-1`                                           | The bucket's region                                             |
| `AWS_ENDPOINT`          | 🛑 `http://localhost:8333`                            | Empty for AWS itself; the endpoint for R2, Spaces, B2, Tigris   |
| `AWS_PRIVATE_BUCKET`    | `<app>-<env>-private`                                 | Bucket for user data, read through presigned URLs               |
| `AWS_PUBLIC_BUCKET`     | `<app>-<env>-public`                                  | Bucket for public assets, served without a signature            |
| `DB_URL`                | `postgresql://postgres:postgres@localhost:5432/<app>` | Postgres; also carries sessions, cache and the queue            |
| `MAIL_URL`              | 🛑 `smtp://localhost:1025`                            | `smtp://user:pass@host:587`                                     |
| `OO_OTLP_ENDPOINT`      | `http://localhost:5080/api/default`                   | Your OpenObserve Cloud region and organization                  |
| `OO_USER`               | ⚠ `root@example.com`                                  | OpenObserve Cloud login                                         |
| `OO_PASSWORD`           | ⚠ `Password1!`                                        | OpenObserve Cloud credential                                    |
| `OO_RUM_CLIENT_TOKEN`   | resolved automatically                                | The token OpenObserve issues; only local and preview self-fetch |
| `REDIS_URL`             | _(unset — `127.0.0.1:6379`)_                          | Redis, for Reverb's pub/sub fan-out                             |
| `REVERB_APP_KEY`        | ⚠ `starter-key`                                       | Public websocket key                                            |
| `REVERB_APP_SECRET`     | ⚠ `starter-secret`                                    | Signs private and presence channel authorisation                |
| `REVERB_HOST`           | `localhost`                                           | Where browsers reach Reverb                                     |

**Set `APP_NAME` first.** Everything that names this application follows it: the database, both buckets,
the OpenTelemetry service name, the RUM application id, the Reverb app id, the session cookie and the
cache prefix. Rename it before you have data, because the database name changes with it.

Also derived, so normally left alone: `AWS_USE_PATH_STYLE_ENDPOINT` (on unless `AWS_ENDPOINT` is empty)
and the RUM host, scheme and organization (from `OO_OTLP_ENDPOINT`). Optional: `AWS_PUBLIC_URL` for a
CDN in front of the public bucket, `OO_RUM_RELEASE` to tie uploaded source maps to a build.

`POSTGRES_DB`, `POSTGRES_PASSWORD` and `ZO_ROOT_USER_PASSWORD` configure the local compose stack rather
than the app. Under `mise preview` the addresses above become compose service names, which is the only
difference that file describes.

## Commands

|                                 |                                              |
| ------------------------------- | -------------------------------------------- |
| `mise bootstrap`                | Install everything and build                 |
| `mise dev`                      | Full dev environment                         |
| `mise check`                    | Format, lint, typecheck, test — what CI runs |
| `mise preview`                  | Production image in compose, locally         |
| `mise build` / `mise build:ssr` | Production assets                            |
| `mise up` / `mise down`         | Backing-service containers                   |

## Gotchas

- **Do not upgrade `@openobserve/browser-rum` past `0.3.x`.** `0.4.0` breaks session replay and trace
  correlation against the v0.91.x server, silently. A test guards the pin.
- **The RUM client token is printed in every page.** That is by design — it only grants ingest — but it
  means anyone can post telemetry to your OpenObserve organization.
