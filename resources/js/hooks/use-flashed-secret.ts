import { router } from "@inertiajs/react";
import { useEffect, useState } from "react";

/**
 * Capture a secret the server flashed exactly once.
 *
 * The value arrives as a flash event rather than a prop, the same way toasts do, and is held in state
 * for this render only — nothing stores the plaintext, so navigating away loses it for good. That is
 * what makes "shown once" true rather than a claim.
 */
export function useFlashedSecret(): string | null {
  const [secret, setSecret] = useState<string | null>(null);

  useEffect(() => {
    return router.on("flash", (event) => {
      const flashed = (event as CustomEvent).detail?.flash?.secret;

      if (typeof flashed === "string") {
        setSecret(flashed);
      }
    });
  }, []);

  return secret;
}
