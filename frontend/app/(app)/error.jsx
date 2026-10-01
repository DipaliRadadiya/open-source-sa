"use client";

import { useTranslations } from "next-intl";
import { FailurePanel } from "@/components/ui/failure-panel";
import { RetryButton } from "@/components/ui/retry-button";

// Error boundary for panel routes; `reset()` re-runs the segment, which
// usually clears a transient API failure.
export default function AppError({ error, reset }) {
  const t = useTranslations("errors");

  return (
    <FailurePanel
      className="justify-center py-16"
      title={t("title")}
      description={t("description")}
      detail={error?.digest}
      action={<RetryButton reset={reset} />}
    />
  );
}
