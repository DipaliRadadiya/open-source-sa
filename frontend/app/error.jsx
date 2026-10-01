"use client";

import { useTranslations } from "next-intl";
import { FailurePanel } from "@/components/ui/failure-panel";
import { RetryButton } from "@/components/ui/retry-button";

// Root-segment boundary: error.jsx never catches throws from its own layout, so
// a failed session/permission fetch in the (app) and admin layouts lands here.
export default function RootError({ error, reset }) {
  const t = useTranslations("errors");

  return (
    // `centered`: replaces the whole viewport, not a slot inside a working shell.
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
