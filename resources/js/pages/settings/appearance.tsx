import { Head } from "@inertiajs/react";

import AppearanceTabs from "#/components/appearance-tabs.tsx";
import Heading from "#/components/heading.tsx";
import { edit as editAppearance } from "#/routes/appearance/index.ts";

export default function Appearance() {
  return (
    <>
      <Head title="Appearance settings" />

      <h1 className="sr-only">Appearance settings</h1>

      <div className="space-y-6">
        <Heading
          variant="small"
          title="Appearance settings"
          description="Update the appearance settings for your account"
        />
        <AppearanceTabs />
      </div>
    </>
  );
}

Appearance.layout = {
  breadcrumbs: [
    {
      title: "Appearance settings",
      href: editAppearance(),
    },
  ],
};
