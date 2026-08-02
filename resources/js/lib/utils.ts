import type { InertiaLinkProps } from "@inertiajs/react";
import { clsx } from "clsx";
import type { ClassValue } from "clsx";
import { twMerge } from "tailwind-merge";

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

export function toUrl(url: NonNullable<InertiaLinkProps["href"]>): string {
  return typeof url === "string" ? url : url.url;
}

/**
 * A date for display, or an em dash when there is nothing to show.
 */
export function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleDateString() : "—";
}
