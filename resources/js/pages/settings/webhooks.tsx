import { Form, Head } from "@inertiajs/react";
import { Trash2, Webhook } from "lucide-react";

import Heading from "#/components/heading.tsx";
import InputError from "#/components/input-error.tsx";
import RevealedSecret from "#/components/revealed-secret.tsx";
import { Badge } from "#/components/ui/badge.tsx";
import { Button } from "#/components/ui/button.tsx";
import { Input } from "#/components/ui/input.tsx";
import { Label } from "#/components/ui/label.tsx";
import { useFlashedSecret } from "#/hooks/use-flashed-secret.ts";
import { formatDate } from "#/lib/utils.ts";
import { destroy, store, update } from "#/routes/webhooks/index.ts";

type Delivery = {
  id: number;
  event_type: string;
  response_status: number | null;
  attempts: number;
  succeeded: boolean;
  created_at: string | null;
};

type Endpoint = {
  id: number;
  url: string;
  description: string | null;
  events: string[];
  active: boolean;
  created_by: string | null;
  created_at: string | null;
  failed_deliveries_count: number;
  recent_deliveries: Delivery[];
};

type Props = {
  team: { slug: string; name: string };
  endpoints: Endpoint[];
};

export default function Webhooks({ team, endpoints }: Props) {
  const secret = useFlashedSecret();

  return (
    <>
      <Head title="Webhooks" />

      <div className="space-y-6">
        <Heading
          title="Webhooks"
          description={`Tell ${team.name}'s systems when something happens. Every delivery is signed, so a receiver can prove it came from here.`}
        />

        {secret ? (
          <RevealedSecret
            title="Copy this signing secret now"
            description="This is the only time it will be shown. Your endpoint needs it to verify that a delivery really came from us."
            secret={secret}
          />
        ) : null}

        <Form action={store({ team: team.slug })} className="space-y-4">
          {({ errors, processing }) => (
            <div className="grid gap-4">
              <div className="grid gap-2 sm:grid-cols-[2fr_1fr] sm:items-start">
                <div className="grid gap-2">
                  <Label htmlFor="url">Endpoint URL</Label>
                  <Input
                    id="url"
                    name="url"
                    type="url"
                    placeholder="https://example.com/webhooks"
                    required
                  />
                  <InputError message={errors.url} />
                </div>

                <div className="grid gap-2">
                  <Label htmlFor="description">Description</Label>
                  <Input id="description" name="description" placeholder="Production" />
                  <InputError message={errors.description} />
                </div>
              </div>

              <div className="grid gap-2">
                <Label htmlFor="events">Events</Label>
                <Input
                  id="events"
                  name="events[0]"
                  defaultValue="*"
                  placeholder="* for every event"
                  required
                />
                <p className="text-muted-foreground text-xs">
                  Use <code className="font-mono">*</code> to receive every event, including types
                  added later.
                </p>
                <InputError message={errors.events} />
              </div>

              <div>
                <Button type="submit" disabled={processing}>
                  <Webhook className="size-4" />
                  Add endpoint
                </Button>
              </div>
            </div>
          )}
        </Form>

        <div className="divide-y rounded-lg border">
          {endpoints.length === 0 ? (
            <p className="text-muted-foreground p-6 text-center text-sm">No endpoints yet.</p>
          ) : (
            endpoints.map((endpoint) => (
              <div key={endpoint.id} className="space-y-3 p-4">
                <div className="flex flex-wrap items-center gap-3">
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                      <code className="truncate font-mono text-sm">{endpoint.url}</code>
                      {endpoint.active ? null : <Badge variant="secondary">disabled</Badge>}
                      {endpoint.failed_deliveries_count > 0 ? (
                        <Badge variant="destructive">
                          {endpoint.failed_deliveries_count} failed
                        </Badge>
                      ) : null}
                    </div>
                    <p className="text-muted-foreground mt-1 text-xs">
                      {endpoint.description ? `${endpoint.description} · ` : ""}
                      {endpoint.events.join(", ")} · Added {formatDate(endpoint.created_at)}
                      {endpoint.created_by ? ` by ${endpoint.created_by}` : ""}
                    </p>
                  </div>

                  <Form action={update({ team: team.slug, webhook: endpoint.id })}>
                    {({ processing }) => (
                      <>
                        <input type="hidden" name="active" value={endpoint.active ? "0" : "1"} />
                        <Button type="submit" variant="ghost" size="sm" disabled={processing}>
                          {endpoint.active ? "Disable" : "Enable"}
                        </Button>
                      </>
                    )}
                  </Form>

                  <Form action={destroy({ team: team.slug, webhook: endpoint.id })}>
                    {({ processing }) => (
                      <Button type="submit" variant="ghost" size="sm" disabled={processing}>
                        <Trash2 className="size-4" />
                        Delete
                      </Button>
                    )}
                  </Form>
                </div>

                {endpoint.recent_deliveries.length > 0 ? (
                  <div className="flex flex-wrap gap-2">
                    {endpoint.recent_deliveries.map((delivery) => (
                      <span
                        key={delivery.id}
                        className="text-muted-foreground bg-muted rounded px-2 py-1 font-mono text-xs"
                        title={`${delivery.event_type} · ${delivery.attempts} attempt(s) · ${formatDate(delivery.created_at)}`}
                      >
                        {delivery.succeeded ? "✓" : "✕"} {delivery.event_type}{" "}
                        {delivery.response_status ?? "—"}
                      </span>
                    ))}
                  </div>
                ) : null}
              </div>
            ))
          )}
        </div>
      </div>
    </>
  );
}
