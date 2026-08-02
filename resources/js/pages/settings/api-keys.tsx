import { Form, Head } from "@inertiajs/react";
import { KeyRound, Trash2 } from "lucide-react";

import Heading from "#/components/heading.tsx";
import InputError from "#/components/input-error.tsx";
import RevealedSecret from "#/components/revealed-secret.tsx";
import { Badge } from "#/components/ui/badge.tsx";
import { Button } from "#/components/ui/button.tsx";
import { Input } from "#/components/ui/input.tsx";
import { Label } from "#/components/ui/label.tsx";
import { useFlashedSecret } from "#/hooks/use-flashed-secret.ts";
import { formatDate } from "#/lib/utils.ts";
import { destroy, store } from "#/routes/api-keys/index.ts";

type ApiKey = {
  id: number;
  name: string;
  masked: string;
  mode: string;
  created_by: string | null;
  created_at: string | null;
  last_used_at: string | null;
  last_used_ip: string | null;
  active: boolean;
};

type Props = {
  team: { slug: string; name: string };
  keys: ApiKey[];
};

export default function ApiKeys({ team, keys }: Props) {
  const secret = useFlashedSecret();

  return (
    <>
      <Head title="API keys" />

      <div className="space-y-6">
        <Heading
          title="API keys"
          description={`Keys let software act on ${team.name}. They belong to the team, so they keep working when a member leaves.`}
        />

        {secret ? (
          <RevealedSecret
            title="Copy this key now"
            description="This is the only time it will be shown. If you lose it, revoke the key and create another."
            secret={secret}
          />
        ) : null}

        <Form action={store({ team: team.slug })} className="space-y-4">
          {({ errors, processing }) => (
            <>
              <div className="grid gap-2 sm:grid-cols-[1fr_auto_auto] sm:items-end">
                <div className="grid gap-2">
                  <Label htmlFor="name">Name</Label>
                  <Input id="name" name="name" placeholder="Billing sync" required />
                  <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                  <Label htmlFor="mode">Mode</Label>
                  <select
                    id="mode"
                    name="mode"
                    defaultValue="test"
                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                  >
                    <option value="test">Test</option>
                    <option value="live">Live</option>
                  </select>
                  <InputError message={errors.mode} />
                </div>

                <Button type="submit" disabled={processing}>
                  <KeyRound className="size-4" />
                  Create key
                </Button>
              </div>
            </>
          )}
        </Form>

        <div className="divide-y rounded-lg border">
          {keys.length === 0 ? (
            <p className="text-muted-foreground p-6 text-center text-sm">No API keys yet.</p>
          ) : (
            keys.map((key) => (
              <div key={key.id} className="flex flex-wrap items-center gap-3 p-4">
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <span className="font-medium">{key.name}</span>
                    <Badge variant={key.mode === "live" ? "default" : "secondary"}>
                      {key.mode}
                    </Badge>
                    {key.active ? null : <Badge variant="destructive">revoked</Badge>}
                  </div>
                  <code className="text-muted-foreground mt-1 block font-mono text-xs">
                    {key.masked}
                  </code>
                  <p className="text-muted-foreground mt-1 text-xs">
                    Created {formatDate(key.created_at)}
                    {key.created_by ? ` by ${key.created_by}` : ""} · Last used{" "}
                    {formatDate(key.last_used_at)}
                    {key.last_used_ip ? ` from ${key.last_used_ip}` : ""}
                  </p>
                </div>

                {key.active ? (
                  <Form action={destroy({ team: team.slug, apiKey: key.id })}>
                    {({ processing }) => (
                      <Button type="submit" variant="ghost" size="sm" disabled={processing}>
                        <Trash2 className="size-4" />
                        Revoke
                      </Button>
                    )}
                  </Form>
                ) : null}
              </div>
            ))
          )}
        </div>
      </div>
    </>
  );
}
