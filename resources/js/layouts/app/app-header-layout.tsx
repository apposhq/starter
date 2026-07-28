import { AppContent } from "#/components/app-content.tsx";
import { AppHeader } from "#/components/app-header.tsx";
import { AppShell } from "#/components/app-shell.tsx";
import type { AppLayoutProps } from "#/types/index.ts";

export default function AppHeaderLayout({ children, breadcrumbs }: AppLayoutProps) {
  return (
    <AppShell variant="header">
      <AppHeader breadcrumbs={breadcrumbs} />
      <AppContent variant="header">{children}</AppContent>
    </AppShell>
  );
}
