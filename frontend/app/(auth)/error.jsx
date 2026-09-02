"use client";

import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { RetryButton } from "@/components/ui/retry-button";

/**
 * Segment boundary for login and register.
 *
 * These two are the only screens someone can reach without already being in,
 * so a throw here is the worst-placed one in the panel: the root boundary
 * replaces the whole page, and a person who cannot sign in is left on a
 * full-screen error with no field to type into and no reason to believe
 * retrying will help.
 *
 * Sized to the card slot instead — the branding, the locale switcher and the
 * layout stay, so the screen still reads as the sign-in page having a bad
 * moment rather than the panel being gone.
 */
export default function AuthError({ error, reset }) {
  const t = useTranslations("errors");

  return (
    <div
      role="alert"
      className="flex flex-col items-center gap-4 rounded-xl border border-destructive/30 bg-destructive/5 px-6 py-10 text-center"
    >
      <span className="flex size-12 items-center justify-center rounded-full bg-destructive/10 text-destructive">
        <TriangleAlert className="size-5" />
      </span>
      <div className="space-y-1">
        <p className="font-medium">{t("title")}</p>
        <p className="text-sm text-muted-foreground">{t("description")}</p>
        {error?.digest ? (
          <p className="pt-1 font-mono text-xs text-muted-foreground">{error.digest}</p>
        ) : null}
      </div>
      <RetryButton reset={reset} />
    </div>
  );
}
