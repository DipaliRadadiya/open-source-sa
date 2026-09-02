"use client";

import { useTranslations } from "next-intl";
import { FailurePanel } from "@/components/ui/failure-panel";
import { RetryButton } from "@/components/ui/retry-button";

// Root-segment boundary. The (app) and admin layouts fetch the session and the
// permission catalog, and an error.jsx never catches throws from its OWN
// layout — only from its children. Without this, a failed session fetch in the
// panel layout escaped to Next's unstyled default error page.
export default function RootError({ error, reset }) {
  const t = useTranslations("errors");

  return (
    // `centered`: this one replaces the whole viewport rather than a slot
    // inside a shell that is still standing.
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
