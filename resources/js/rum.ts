/**
 * These two are pinned to an exact 0.3.x and must not be bumped without checking the OpenObserve server
 * first. 0.4.0 changed two things that the v0.91.x server has no support for, and both fail silently:
 *
 * - Session replay switched to a delta-encoded full snapshot (`format: 1`). The server's player cannot
 *   reconstruct it, so replays render as a blank page with a working mouse cursor.
 * - The trace id written onto RUM events lost its zero padding, while the traceparent header kept it.
 *   The ids are timestamp-prefixed and so always start with a zero, which breaks every RUM-to-trace
 *   join and shows up as "No correlated traces found for this session".
 *
 * Neither surfaces as an error anywhere: the browser posts 200, the data lands, and only the UI is
 * wrong. Upgrade once the server ships a player that reads `format: 1`.
 *
 * Both are imported dynamically inside initializeRum, so nothing above is fetched unless RUM is on.
 */

/**
 * Shape emitted by the root template. Keys are the SDK's own option names, so this is a hand-off
 * rather than a translation.
 *
 * @see app/Providers/AppServiceProvider.php
 * @see resources/views/app.blade.php
 */
type RumConfig = {
  apiVersion: string;
  applicationId: string;
  clientToken: string;
  defaultPrivacyLevel: "allow" | "mask" | "mask-user-input";
  env: string;
  insecureHTTP: boolean;
  organizationIdentifier: string;
  service: string;
  sessionReplaySampleRate: number;
  sessionSampleRate: number;
  site: string;
  version: string;
};

function readConfig(): RumConfig | null {
  const element = document.getElementById("rum-config");

  if (!element?.textContent) {
    return null;
  }

  try {
    return JSON.parse(element.textContent) as RumConfig;
  } catch {
    // A malformed block must not take the app down with it; telemetry is never worth the page.
    return null;
  }
}

/**
 * Start browser telemetry. Safe to call unconditionally: it no-ops during SSR and whenever the server
 * omitted the config block, which is how RUM stays off without a client token.
 */
export async function initializeRum(): Promise<void> {
  // Vite replaces this with a literal, so the dynamic imports below become unreachable in the SSR build
  // and the ~450 KB SDK is never bundled into it. It has to be this rather than a runtime DOM check, which
  // the bundler cannot fold — that left the whole SDK sitting in bootstrap/ssr as dead weight.
  if (import.meta.env.SSR) {
    return;
  }

  const config = readConfig();

  if (!config) {
    return;
  }

  // Imported here rather than at module scope so the ~143 KB SDK stays out of the entry chunk and is
  // never fetched on a page load where the server sent no config, which is the default without a token.
  const [{ openobserveRum }, { openobserveLogs }] = await Promise.all([
    import("@openobserve/browser-rum"),
    import("@openobserve/browser-logs"),
  ]);

  // Everything except the three RUM-only options is shared with the logs SDK. Note sessionSampleRate is
  // in here: both SDKs sample independently and browser-core defaults a missing rate to 100, so leaving
  // it out would thin RUM sessions while browser logs kept arriving in full, leaving log records
  // pointing at sessions that were never recorded.
  const { applicationId, defaultPrivacyLevel, sessionReplaySampleRate, ...shared } = config;

  openobserveRum.init({
    ...shared,
    applicationId,
    defaultPrivacyLevel,
    sessionReplaySampleRate,
    trackResources: true,
    trackLongTasks: true,
    trackUserInteractions: true,
    // Stamps same-origin requests with a W3C traceparent, which Laravel's tracecontext propagator
    // continues, so a browser error links to the exact backend trace that served it.
    //
    // Compared as a parsed origin, not a string prefix: `startsWith` would also match hosts that merely
    // begin with this origin — `https://acme.com.attacker.test` against `https://acme.com` — leaking the
    // trace id off-origin and adding a header that turns those requests into preflighted ones the third
    // party's CORS policy then rejects.
    //
    // Correlation depends on the pinned SDK version; see the note above the imports.
    allowedTracingUrls: [
      {
        match: (url: string) => {
          try {
            return new URL(url, window.location.href).origin === window.location.origin;
          } catch {
            return false;
          }
        },
        propagatorTypes: ["tracecontext"],
      },
    ],
  });

  openobserveLogs.init({
    ...shared,
    // Uncaught errors and unhandled rejections, once the SDK has loaded.
    forwardErrorsToLogs: true,
    // forwardErrorsToLogs does not cover console calls; without this, console.error is visible in
    // devtools and nowhere else.
    forwardConsoleLogs: ["error", "warn"],
  });

  openobserveRum.startSessionReplayRecording();
}
