"use client";

import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { TriangleAlert, RotateCw } from "lucide-react";
import { Button } from "@/components/ui/button";

/**
 * "This part didn't load" — for a failed fetch that only cost us one section of
 * the page. The page keeps its title, toolbar and sidebar; only the content
 * that actually failed says so.
 *
 * Distinct from EmptyState on purpose: "no results" and "we couldn't ask" are
 * different answers, and rendering a failure as an empty list is a lie about
 * the user's data.
 *
 * `status` and `failure` come straight from `read()`. They are what turns this
 * from "something went wrong" into something someone can act on — a 403 is a
 * permissions problem, a 500 is the backend's, and a shape mismatch is ours.
 * Without them the box was unfalsifiable: it looked identical whichever had
 * happened, and the reason existed nowhere else either.
 */
export function LoadFailed({ description, status = null, failure = null }) {
  const t = useTranslations("errors");
  const router = useRouter();

  // Said in the user's terms first. 403 and 404 are their situation, not a
  // fault; 5xx and a dead request are ours to fix; a shape mismatch is a bug
  // in this panel and should not be dressed up as a network blip.
  const reason =
    failure === "shape"
      ? t("reason.shape")
      : failure === "network"
        ? t("reason.network")
        : status === 403
          ? t("reason.forbidden")
          : status === 404
            ? t("reason.notFound")
            : status >= 500
              ? t("reason.server")
              : null;

  return (
    <div
      role="alert"
      className="flex flex-col items-center justify-center gap-3 rounded-xl border border-destructive/30 bg-destructive/5 py-16 text-center"
    >
      <span className="flex size-12 items-center justify-center rounded-full bg-destructive/10 text-destructive">
        <TriangleAlert className="size-5" />
      </span>
      <div className="space-y-1">
        <p className="font-medium">{t("partial.title")}</p>
        <p className="max-w-sm text-sm text-muted-foreground">
          {reason ?? description ?? t("partial.description")}
        </p>
        {/* The code, small and last: meaningless to most people, and the first
            thing anyone asks for when reporting this. Not shown for a shape
            failure — the request succeeded, so printing "Error 200" would
            point at the one part that worked. */}
        {status && status >= 400 ? (
          <p className="pt-0.5 font-mono text-xs text-muted-foreground">
            {t("reason.code", { status })}
          </p>
        ) : null}
      </div>
      {/* refresh() re-runs the server component, which is the whole fix when the
          failure was a one-off. */}
      <Button variant="outline" size="sm" onClick={() => router.refresh()}>
        <RotateCw className="size-4" />
        {t("retry")}
      </Button>
    </div>
  );
}
