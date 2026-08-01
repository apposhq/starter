import { createInertiaApp } from "@inertiajs/react";

import { Toaster } from "#/components/ui/sonner.tsx";
import { TooltipProvider } from "#/components/ui/tooltip.tsx";
import { initializeTheme } from "#/hooks/use-appearance.tsx";
import AppLayout from "#/layouts/app-layout.tsx";
import AuthLayout from "#/layouts/auth-layout.tsx";
import SettingsLayout from "#/layouts/settings/layout.tsx";
import { initializeRum } from "#/rum.ts";

// Taken from the shared Inertia prop rather than a VITE_ variable, so it follows APP_NAME at runtime.
// A build-time constant cannot: the image is built once and configured per environment, so the name
// baked in at `vp build` is whatever the builder happened to have, and the same bundle deployed under
// two names would title both the same. Assigned in withApp below, which also works under SSR where
// there is no document to read it from.
let appName = "Starter";

// No-ops during SSR and whenever the server sent no RUM config. Not awaited: it loads the SDK lazily,
// so blocking the app on telemetry would trade a real page for a nice-to-have. That laziness also means
// the SDK's error handler is installed after mount, so a failure to mount is caught by the browser's
// own unhandled-rejection reporting rather than by RUM.
void initializeRum();

// Not awaited so initializeTheme below runs before the first paint. A rejection here means the app
// never mounted, and stays an unhandled rejection so the console and RUM both see it.
void createInertiaApp({
  title: (title) => (title ? `${title} - ${appName}` : appName),
  layout: (name) => {
    switch (true) {
      case name === "welcome":
        return null;
      case name.startsWith("auth/"):
        return AuthLayout;
      case name.startsWith("settings/"):
      case name.startsWith("teams/"):
        return [AppLayout, SettingsLayout];
      default:
        return AppLayout;
    }
  },
  strictMode: true,
  withApp(app, { page }) {
    // The only hook that both receives the page and runs before the tree renders, so before <Head>
    // first calls title() above. setup() also has the props but is mutually exclusive with this one.
    appName = (page.props as { name?: string }).name || appName;

    return (
      <TooltipProvider delay={0}>
        {app}
        <Toaster />
      </TooltipProvider>
    );
  },
  progress: {
    color: "#4B5563",
  },
});

// This will set light / dark mode on load...
initializeTheme();
