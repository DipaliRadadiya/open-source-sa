"use client";

import { useTranslations } from "next-intl";
import { TriangleAlert, RotateCw } from "lucide-react";
import { cn } from "@/lib/utils";
import { useRefresh } from "@/hooks/use-refresh";
import { Button } from "@/components/ui/button";

// Distinct from EmptyState: a failure must never render as an empty list.
// `status` and `failure` come from `read()` and select the explanation.
export function LoadFailed({ description, status = null, failure = null, message = null, debug = false }) {
  const t = useTranslations("errors");
  const { refresh, pending } = useRefresh();

  // Which kind of failure, or null when it cannot be told.
  const BY_STATUS = { 403: "forbidden", 404: "notFound", 429: "rateLimited" };
  const kind =
    failure === "shape" || failure === "network"
      ? failure
      : (BY_STATUS[status] ?? (status >= 500 ? "server" : null));

  // The heading names the specific reason when known.
  const heading = kind ? t(`reason.${kind}.title`) : t("partial.title");
  const reason = kind ? t(`reason.${kind}.body`) : null;

  return (
    <div
      role="alert"
      className="flex flex-col items-center justify-center gap-3 rounded-xl border border-destructive/30 bg-destructive/5 py-16 text-center"
    >
      <span className="flex size-12 items-center justify-center rounded-full bg-destructive/10 text-destructive">
        <TriangleAlert className="size-5" />
      </span>
      <div className="space-y-1">
        <p className="font-medium">{heading}</p>
        <p className="max-w-sm text-sm text-muted-foreground">
          {reason ?? description ?? t("partial.description")}
        </p>
        {/* The API's own message, quoted so it is not read as the panel's. */}
        {message ? (
          <blockquote className="mx-auto max-w-sm border-l-2 border-destructive/30 py-0.5 pl-3 text-left text-sm text-foreground">
            {message}
          </blockquote>
        ) : null}
        {debug ? (
          <p className="text-xs text-amber-700 dark:text-amber-500">{t("request.debugWarning")}</p>
        ) : null}
        {/* Status code for reporting; not for shape failures (the request
            succeeded) or rate limits. */}
        {status && status >= 400 && kind !== "rateLimited" ? (
          <p className="pt-0.5 font-mono text-xs text-muted-foreground">
            {t("reason.code", { status })}
          </p>
        ) : null}
      </div>
      <Button variant="outline" size="sm" onClick={refresh} disabled={pending}>
        <RotateCw className={cn("size-4", pending && "animate-spin")} />
        {t("retry")}
      </Button>
    </div>
  );
}
