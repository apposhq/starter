import { Link } from "@inertiajs/react";

import {
  SidebarGroup,
  SidebarGroupLabel,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
} from "#/components/ui/sidebar.tsx";
import { useCurrentUrl } from "#/hooks/use-current-url.ts";
import type { NavItem } from "#/types/index.ts";

export function NavMain({ items }: { items: NavItem[] }) {
  const { isCurrentUrl } = useCurrentUrl();

  return (
    <SidebarGroup className="px-2 py-0">
      <SidebarGroupLabel>Platform</SidebarGroupLabel>
      <SidebarMenu>
        {items.map((item) => (
          <SidebarMenuItem key={item.title}>
            <SidebarMenuButton
              render={<Link href={item.href} prefetch />}
              isActive={isCurrentUrl(item.href)}
              tooltip={{ children: item.title }}
            >
              {item.icon && <item.icon />}
              <span>{item.title}</span>
            </SidebarMenuButton>
          </SidebarMenuItem>
        ))}
      </SidebarMenu>
    </SidebarGroup>
  );
}
