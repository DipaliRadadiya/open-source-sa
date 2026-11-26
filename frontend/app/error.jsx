"use client";

import { useTranslations } from "next-intl";
import { FailurePanel } from "@/components/ui/failure-panel";
import { RetryButton } from "@/components/ui/retry-button";

// error.jsx can't catch errors from its own layout, so failed session fetches in child layouts land here.
export default function RootError({ error, reset }) {
  const t = useTranslations("errors");

  return (
    <FailurePanel
      centered
      className="w-full max-w-md"
      title={t("title")}
      description={t("description")}
      detail={error?.digest}
      action={<RetryButton reset={reset} />}
    />
  );
}
