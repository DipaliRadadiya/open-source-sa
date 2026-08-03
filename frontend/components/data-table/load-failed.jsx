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
 */
export function LoadFailed({ description }) {
  const t = useTranslations("errors");
  const router = useRouter();

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
          {description ?? t("partial.description")}
        </p>
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
