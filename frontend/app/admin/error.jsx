"use client";

import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { RetryButton } from "@/components/ui/retry-button";

/**
 * Segment boundary for the admin panel, matching the one the server panel has.
 *
 * Without it an SSR throw inside `/admin` escaped to the root boundary, which
 * renders full-screen — so the sidebar, the header and the way back all
 * disappeared along with the page that failed. An admin looking at a broken
 * user list would have had to retype a URL to get anywhere else.
 *
 * `reset()` re-runs the segment, which is usually enough for a transient API
 * failure; the layout around it is untouched either way.
 */
export default function AdminError({ error, reset }) {
  const t = useTranslations("errors");

  return (
    <div
      role="alert"
      className="flex flex-col items-center justify-center gap-4 rounded-xl border border-destructive/30 bg-destructive/5 px-6 py-16 text-center"
    >
      <span className="flex size-12 items-center justify-center rounded-full bg-destructive/10 text-destructive">
        <TriangleAlert className="size-5" />
      </span>
      <div className="space-y-1">
        <p className="font-medium">{t("title")}</p>
        <p className="max-w-md text-sm text-muted-foreground">{t("description")}</p>
        {/* digest, not error.message: the raw message can leak server internals
            and means nothing to the user, but the digest is what correlates
            with the server log. */}
        {error?.digest ? (
          <p className="pt-1 font-mono text-xs text-muted-foreground">{error.digest}</p>
        ) : null}
      </div>
      <RetryButton reset={reset} />
    </div>
  );
}
