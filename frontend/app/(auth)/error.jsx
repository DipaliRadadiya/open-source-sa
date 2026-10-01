"use client";

import { useTranslations } from "next-intl";
import { FailurePanel } from "@/components/ui/failure-panel";
import { RetryButton } from "@/components/ui/retry-button";

/**
 * Error boundary for login and register, sized to the card slot so the
 * branding and locale switcher stay instead of the root full-page error.
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
