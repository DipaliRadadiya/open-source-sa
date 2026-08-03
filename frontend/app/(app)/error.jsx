"use client";

import { useTranslations } from "next-intl";
import { TriangleAlert, RotateCw } from "lucide-react";
import { Button } from "@/components/ui/button";

// Segment-level boundary for every server-panel route: an SSR fetch that throws
// lands here instead of on Next's default error screen. `reset()` re-runs the
// segment, which is usually enough for a transient API failure.
export default function AppError({ error, reset }) {
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
      <Button variant="outline" onClick={reset}>
        <RotateCw className="size-4" />
        {t("retry")}
      </Button>
    </div>
  );
}
