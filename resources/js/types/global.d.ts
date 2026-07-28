import type { Auth, Team } from "#/types/index.ts";

declare module "react" {
  interface InputHTMLAttributes<T> {
    passwordrules?: string;
  }
}

declare module "@inertiajs/core" {
  export interface InertiaConfig {
    sharedPageProps: {
      name: string;
      auth: Auth;
      sidebarOpen: boolean;
      currentTeam: Team | null;
      teams: Team[];
      [key: string]: unknown;
    };
  }
}
