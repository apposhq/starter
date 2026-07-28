import { usePage } from "@inertiajs/react";
import { ChevronsUpDown } from "lucide-react";

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from "#/components/ui/dropdown-menu.tsx";
import {
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  useSidebar,
} from "#/components/ui/sidebar.tsx";
import { UserInfo } from "#/components/user-info.tsx";
import { UserMenuContent } from "#/components/user-menu-content.tsx";
import { useIsMobile } from "#/hooks/use-mobile.tsx";

export function NavUser() {
  const { auth, currentTeam } = usePage().props;
  const { state } = useSidebar();
  const isMobile = useIsMobile();

  return (
    <SidebarMenu>
      <SidebarMenuItem>
        <DropdownMenu>
          <DropdownMenuTrigger
            render={
              <SidebarMenuButton
                size="lg"
                className="group text-sidebar-accent-foreground data-popup-open:bg-sidebar-accent"
                data-test="sidebar-menu-button"
              />
            }
          >
            <UserInfo user={auth.user} team={currentTeam} />
            <ChevronsUpDown className="ml-auto size-4" />
          </DropdownMenuTrigger>
          <DropdownMenuContent
            className="w-(--anchor-width) min-w-56 rounded-lg"
            align="end"
            side={isMobile ? "bottom" : state === "collapsed" ? "left" : "bottom"}
          >
            <UserMenuContent user={auth.user} />
          </DropdownMenuContent>
        </DropdownMenu>
      </SidebarMenuItem>
    </SidebarMenu>
  );
}
