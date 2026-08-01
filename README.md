# Starter

Multi-tenant SaaS starter. Laravel 13 + Octane, Inertia 3 + React 19, Postgres, OpenTelemetry.
Teams, passkeys and 2FA are built — add features on top.

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

| Role   | Permissions                                             |
| ------ | ------------------------------------------------------- |
| Owner  | all seven                                               |
| Admin  | `team:update`, `invitation:create`, `invitation:cancel` |
| Member | none                                                    |

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

| Variable                | `mise dev`                                                             | Set it to                                                       |
| ----------------------- | ---------------------------------------------------------------------- | --------------------------------------------------------------- |
| `APP_KEY`               | ⚠ `base64:g7J5n6…PZLo8Td0=`                                            | A generated key — it signs signed URLs and verification links   |
| `APP_URL`               | `http://localhost:8000`                                                | The public origin; drives Reverb origins and URL generation     |
| `AWS_ACCESS_KEY_ID`     | ⚠ `starter`                                                            | Object storage credential                                       |
| `AWS_SECRET_ACCESS_KEY` | ⚠ `starter`                                                            | Object storage credential                                       |
| `AWS_DEFAULT_REGION`    | `us-east-1`                                                            | The bucket's region                                             |
| `AWS_ENDPOINT`          | 🛑 `http://localhost:8333`                                             | Empty for AWS itself; the endpoint for R2, Spaces, B2, Tigris   |
| `AWS_PRIVATE_BUCKET`    | `starter-private`                                                      | Bucket for user data, read through presigned URLs               |
| `AWS_PUBLIC_BUCKET`     | `starter-public`                                                       | Bucket for public assets, served without a signature            |
| `DB_URL_PRIMARY`        | `postgresql://postgres:postgres@localhost:5432/primary?sslmode=prefer` | Postgres; also carries sessions, cache and the queue            |
| `MAIL_URL`              | 🛑 `smtp://localhost:1025`                                             | `smtp://user:pass@host:587`                                     |
| `OO_OTLP_ENDPOINT`      | `http://localhost:5080/api/default`                                    | Your OpenObserve Cloud region and organization                  |
| `OO_USER`               | ⚠ `root@example.com`                                                   | OpenObserve Cloud login                                         |
| `OO_PASSWORD`           | ⚠ `Password1!`                                                         | OpenObserve Cloud credential                                    |
| `OO_RUM_CLIENT_TOKEN`   | resolved automatically                                                 | The token OpenObserve issues; only local and preview self-fetch |
| `REDIS_URL`             | _(unset — `127.0.0.1:6379`)_                                           | Redis, for Reverb's pub/sub fan-out                             |
| `REVERB_APP_KEY`        | ⚠ `starter-key`                                                        | Public websocket key                                            |
| `REVERB_APP_SECRET`     | ⚠ `starter-secret`                                                     | Signs private and presence channel authorisation                |
| `REVERB_HOST`           | `localhost`                                                            | Where browsers reach Reverb                                     |

Derived, so normally left alone: `AWS_USE_PATH_STYLE_ENDPOINT` (on unless `AWS_ENDPOINT` is empty),
`REVERB_APP_ID` and the OTel service name (from `APP_NAME`), and the RUM host, scheme and organization
(from `OO_OTLP_ENDPOINT`). Optional: `APP_NAME`, `AWS_PUBLIC_URL` for a CDN in front of the public
bucket, `OO_RUM_RELEASE` to tie uploaded source maps to a build.

`POSTGRES_PASSWORD` and `ZO_ROOT_USER_PASSWORD` configure the local compose stack, not the app. Under
`mise preview` the addresses above become compose service names — the nine keys `compose.yml` sets are
exactly that difference.

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
