import { Copy } from "lucide-react";

import { Button } from "#/components/ui/button.tsx";
import { useClipboard } from "#/hooks/use-clipboard.ts";

type Props = {
  title: string;
  description: string;
  secret: string;
};

/**
 * The one and only render of a freshly minted secret.
 *
 * Deliberately loud: this is the single moment the plaintext exists outside the customer's own records,
 * and there is no path that shows it again.
 */
export default function RevealedSecret({ title, description, secret }: Props) {
  const [copiedText, copy] = useClipboard();

  return (
    <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/40">
      <p className="text-sm font-medium">{title}</p>
      <p className="text-muted-foreground mt-1 text-sm">{description}</p>

      <div className="mt-3 flex items-center gap-2">
        <code className="bg-background flex-1 overflow-x-auto rounded-md px-3 py-2 font-mono text-sm">
          {secret}
        </code>
        <Button type="button" variant="outline" size="sm" onClick={() => void copy(secret)}>
          <Copy className="size-4" />
          {copiedText === secret ? "Copied" : "Copy"}
        </Button>
      </div>
    </div>
  );
}
