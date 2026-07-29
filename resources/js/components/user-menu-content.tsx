import { Link, router } from "@inertiajs/react";
import { LogOut, Settings } from "lucide-react";

import {
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
} from "#/components/ui/dropdown-menu.tsx";
import { UserInfo } from "#/components/user-info.tsx";
import { useMobileNavigation } from "#/hooks/use-mobile-navigation.ts";
import { logout } from "#/routes/index.ts";
import { edit } from "#/routes/profile/index.ts";
import type { User } from "#/types/index.ts";

type Props = {
  user: User;
};

export function UserMenuContent({ user }: Props) {
  const cleanup = useMobileNavigation();

  const handleLogout = () => {
    cleanup();
    router.flushAll();
  };

  return (
    <>
      <DropdownMenuGroup>
        <DropdownMenuLabel className="p-0 font-normal">
          <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo user={user} showEmail={true} />
          </div>
        </DropdownMenuLabel>
      </DropdownMenuGroup>
      <DropdownMenuSeparator />
      <DropdownMenuGroup>
        <DropdownMenuItem
          render={
            <Link
              className="block w-full cursor-pointer"
              href={edit()}
              prefetch
              onClick={cleanup}
            />
          }
        >
          <Settings className="mr-2" />
          Settings
        </DropdownMenuItem>
      </DropdownMenuGroup>
      <DropdownMenuSeparator />
      <DropdownMenuItem
        // Menu items default to a non-button element; this one renders Link as="button".
        nativeButton
        render={
          <Link
            className="block w-full cursor-pointer"
            href={logout()}
            as="button"
            onClick={handleLogout}
            data-test="logout-button"
          />
        }
      >
        <LogOut className="mr-2" />
        Log out
      </DropdownMenuItem>
    </>
  );
}
