import { Link, usePage } from "@inertiajs/react";
import { BookOpen, FolderGit2, LayoutGrid } from "lucide-react";

import AppLogo from "#/components/app-logo.tsx";
import { NavFooter } from "#/components/nav-footer.tsx";
import { NavMain } from "#/components/nav-main.tsx";
import { NavUser } from "#/components/nav-user.tsx";
import { TeamSwitcher } from "#/components/team-switcher.tsx";
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
} from "#/components/ui/sidebar.tsx";
import { dashboard } from "#/routes/index.ts";
import type { NavItem } from "#/types/index.ts";

export function AppSidebar() {
  const page = usePage();
  const dashboardUrl = page.props.currentTeam ? dashboard(page.props.currentTeam.slug) : "/";

  const mainNavItems: NavItem[] = [
    {
      title: "Dashboard",
      href: dashboardUrl,
      icon: LayoutGrid,
    },
  ];

  const footerNavItems: NavItem[] = [
    {
      title: "Repository",
      href: "https://github.com/laravel/react-starter-kit",
      icon: FolderGit2,
    },
    {
      title: "Documentation",
      href: "https://laravel.com/docs/starter-kits#react",
      icon: BookOpen,
    },
  ];

  return (
    <Sidebar collapsible="icon" variant="inset">
      <SidebarHeader>
        <SidebarMenu>
          <SidebarMenuItem>
            <SidebarMenuButton size="lg" render={<Link href={dashboardUrl} prefetch />}>
              <AppLogo />
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>
        <SidebarMenu>
          <SidebarMenuItem>
            <TeamSwitcher />
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarHeader>

      <SidebarContent>
        <NavMain items={mainNavItems} />
      </SidebarContent>

      <SidebarFooter>
        <NavFooter items={footerNavItems} className="mt-auto" />
        <NavUser />
      </SidebarFooter>
    </Sidebar>
  );
}
