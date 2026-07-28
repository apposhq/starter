import { AppContent } from "#/components/app-content.tsx";
import { AppShell } from "#/components/app-shell.tsx";
import { AppSidebarHeader } from "#/components/app-sidebar-header.tsx";
import { AppSidebar } from "#/components/app-sidebar.tsx";
import type { AppLayoutProps } from "#/types/index.ts";

export default function AppSidebarLayout({ children, breadcrumbs = [] }: AppLayoutProps) {
  return (
    <AppShell variant="sidebar">
      <AppSidebar />
      <AppContent variant="sidebar" className="overflow-x-hidden">
        <AppSidebarHeader breadcrumbs={breadcrumbs} />
        {children}
      </AppContent>
    </AppShell>
  );
}
