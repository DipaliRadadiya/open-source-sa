"use client";

import { useTranslations } from "next-intl";
import { FailurePanel } from "@/components/ui/failure-panel";
import { RetryButton } from "@/components/ui/retry-button";

/**
 * Segment boundary for the admin panel. Without it an SSR throw escapes to the
 * full-screen root boundary and the admin shell (sidebar, header) disappears.
 */
export default function AdminError({ error, reset }) {
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
