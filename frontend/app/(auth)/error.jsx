"use client";

import { useTranslations } from "next-intl";
import { FailurePanel } from "@/components/ui/failure-panel";
import { RetryButton } from "@/components/ui/retry-button";

// Sized to the card slot so branding and the locale switcher stay visible.
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
