"use client";

import { useTranslations } from "next-intl";
import { FailurePanel } from "@/components/ui/failure-panel";
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
    <FailurePanel
      className="py-10"
      title={t("title")}
      description={t("description")}
      detail={error?.digest}
      action={<RetryButton reset={reset} />}
    />
  );
}
