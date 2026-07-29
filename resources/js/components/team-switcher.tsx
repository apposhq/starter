import { router, usePage } from "@inertiajs/react";
import { Check, ChevronsUpDown, Plus, Users } from "lucide-react";
import { useState } from "react";

import CreateTeamModal from "#/components/create-team-modal.tsx";
import { Button } from "#/components/ui/button.tsx";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "#/components/ui/dropdown-menu.tsx";
import { useIsMobile } from "#/hooks/use-mobile.tsx";
import { switchMethod } from "#/routes/teams/index.ts";
import type { Team } from "#/types/index.ts";

type TeamSwitcherProps = {
  inHeader?: boolean;
};

export function TeamSwitcher({ inHeader = false }: TeamSwitcherProps) {
  const page = usePage();
  const isMobile = useIsMobile();
  const [createTeamOpen, setCreateTeamOpen] = useState(false);
  const currentTeam = page.props.currentTeam;
  const teams = page.props.teams ?? [];

  const switchTeam = (team: Team) => {
    const previousTeamSlug = currentTeam?.slug;

    router.visit(switchMethod(team.slug), {
      onFinish: () => {
        if (!previousTeamSlug || typeof window === "undefined") {
          router.reload();

          return;
        }

        const currentUrl = `${window.location.pathname}${window.location.search}${window.location.hash}`;
        const segment = `/${previousTeamSlug}`;

        if (currentUrl.includes(segment)) {
          router.visit(currentUrl.replace(segment, `/${team.slug}`), {
            replace: true,
          });

          return;
        }

        router.reload();
      },
    });
  };

  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        render={
          <Button
            variant="ghost"
            data-test="team-switcher-trigger"
            className={
              inHeader
                ? "h-8 gap-1 px-2"
                : "data-popup-open:bg-sidebar-accent data-popup-open:text-sidebar-accent-foreground w-full justify-start px-2 has-[>svg]:px-2"
            }
          />
        }
      >
        <Users
          className={
            inHeader ? "hidden" : "hidden size-4 shrink-0 group-data-[collapsible=icon]:block"
          }
        />
        <div
          className={
            inHeader
              ? "grid flex-1 text-left text-sm leading-tight"
              : "grid flex-1 text-left text-sm leading-tight group-data-[collapsible=icon]:hidden"
          }
        >
          <span className={inHeader ? "max-w-30 truncate font-medium" : "truncate font-semibold"}>
            {currentTeam?.name ?? "Select team"}
          </span>
        </div>
        <ChevronsUpDown
          className={
            inHeader ? "size-4 opacity-50" : "ml-auto group-data-[collapsible=icon]:hidden"
          }
        />
      </DropdownMenuTrigger>
      <DropdownMenuContent
        className={inHeader ? "w-56" : "w-(--anchor-width) min-w-56 rounded-lg"}
        side={inHeader ? undefined : isMobile ? "bottom" : "right"}
        align={inHeader ? "end" : "start"}
        sideOffset={inHeader ? undefined : 4}
      >
        <DropdownMenuGroup>
          <DropdownMenuLabel className="text-muted-foreground text-xs">Teams</DropdownMenuLabel>
          {teams.map((team) => (
            <DropdownMenuItem
              key={team.id}
              data-test="team-switcher-item"
              className={inHeader ? "cursor-pointer gap-2" : "cursor-pointer gap-2 p-2"}
              onClick={() => switchTeam(team)}
            >
              {team.name}
              {currentTeam?.id === team.id && (
                <Check className={inHeader ? "ml-auto size-4" : "ml-auto h-4 w-4"} />
              )}
            </DropdownMenuItem>
          ))}
        </DropdownMenuGroup>
        <DropdownMenuSeparator />
        <DropdownMenuItem
          data-test="team-switcher-new-team"
          className={inHeader ? "cursor-pointer gap-2" : "cursor-pointer gap-2 p-2"}
          onClick={() => setCreateTeamOpen(true)}
        >
          <Plus className={inHeader ? "size-4" : "h-4 w-4"} />
          <span className="text-muted-foreground">New team</span>
        </DropdownMenuItem>
      </DropdownMenuContent>
      <CreateTeamModal open={createTeamOpen} onOpenChange={setCreateTeamOpen} />
    </DropdownMenu>
  );
}
