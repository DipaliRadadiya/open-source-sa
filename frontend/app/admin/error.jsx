"use client";

import { useTranslations } from "next-intl";
import { FailurePanel } from "@/components/ui/failure-panel";
import { RetryButton } from "@/components/ui/retry-button";

/**
 * Segment boundary for the admin panel, matching the one the server panel has.
 *
 * Without it an SSR throw inside `/admin` escaped to the root boundary, which
 * renders full-screen — so the sidebar, the header and the way back all
 * disappeared along with the page that failed. An admin looking at a broken
 * user list would have had to retype a URL to get anywhere else.
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
