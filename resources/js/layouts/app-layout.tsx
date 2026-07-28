import AppLayoutTemplate from "#/layouts/app/app-sidebar-layout.tsx";
import type { BreadcrumbItem } from "#/types/index.ts";

export default function AppLayout({
  breadcrumbs = [],
  children,
}: {
  breadcrumbs?: BreadcrumbItem[];
  children: React.ReactNode;
}) {
  return <AppLayoutTemplate breadcrumbs={breadcrumbs}>{children}</AppLayoutTemplate>;
}
