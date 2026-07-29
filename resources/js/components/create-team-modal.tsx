import { Form } from "@inertiajs/react";
import type { ReactElement } from "react";
import { useState } from "react";

import InputError from "#/components/input-error.tsx";
import { Button } from "#/components/ui/button.tsx";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "#/components/ui/dialog.tsx";
import { Input } from "#/components/ui/input.tsx";
import { Label } from "#/components/ui/label.tsx";
import { store } from "#/routes/teams/index.ts";

// Callers that already own a trigger (a menu item, say) drive this with `open`/`onOpenChange` and
// pass no children: a DialogTrigger cannot be composed onto a menu item without one of them losing
// its semantics, and the trigger stops firing entirely.
export default function CreateTeamModal({
  children,
  open: controlledOpen,
  onOpenChange,
}: {
  children?: ReactElement;
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
}) {
  const [uncontrolledOpen, setUncontrolledOpen] = useState(false);
  const open = controlledOpen ?? uncontrolledOpen;
  const setOpen = onOpenChange ?? setUncontrolledOpen;

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      {children ? <DialogTrigger render={children} /> : null}
      <DialogContent>
        <Form
          key={String(open)}
          {...store.form()}
          className="space-y-6"
          onSuccess={() => setOpen(false)}
        >
          {({ errors, processing }) => (
            <>
              <DialogHeader>
                <DialogTitle>Create a new team</DialogTitle>
                <DialogDescription>Create a new team to collaborate with others.</DialogDescription>
              </DialogHeader>

              <div className="grid gap-2">
                <Label htmlFor="name">Team name</Label>
                <Input
                  id="name"
                  name="name"
                  data-test="create-team-name"
                  placeholder="My team"
                  required
                />
                <InputError message={errors.name} />
              </div>

              <DialogFooter className="gap-2">
                <DialogClose render={<Button variant="secondary" />}>Cancel</DialogClose>

                <Button type="submit" data-test="create-team-submit" disabled={processing}>
                  Create team
                </Button>
              </DialogFooter>
            </>
          )}
        </Form>
      </DialogContent>
    </Dialog>
  );
}
