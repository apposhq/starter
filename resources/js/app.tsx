import { createInertiaApp } from "@inertiajs/react";

import { Toaster } from "#/components/ui/sonner.tsx";
import { TooltipProvider } from "#/components/ui/tooltip.tsx";
import { initializeTheme } from "#/hooks/use-appearance.tsx";
import AppLayout from "#/layouts/app-layout.tsx";
import AuthLayout from "#/layouts/auth-layout.tsx";
import SettingsLayout from "#/layouts/settings/layout.tsx";

const appName = import.meta.env.VITE_APP_NAME || "Laravel";

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
  withApp(app) {
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
