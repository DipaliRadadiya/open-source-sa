"use client";

import { useTranslations } from "next-intl";
import { FailurePanel } from "@/components/ui/failure-panel";
import { RetryButton } from "@/components/ui/retry-button";

// Keeps the admin shell on an SSR throw instead of the full-screen root boundary.
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
