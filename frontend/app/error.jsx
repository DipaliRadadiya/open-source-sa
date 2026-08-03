"use client";

import { useTranslations } from "next-intl";
import { TriangleAlert, RotateCw } from "lucide-react";
import { Button } from "@/components/ui/button";

// Root-segment boundary. The (app) and admin layouts fetch the session and the
// permission catalog, and an error.jsx never catches throws from its OWN
// layout — only from its children. Without this, a failed session fetch in the
// panel layout escaped to Next's unstyled default error page.
export default function RootError({ error, reset }) {
  const t = useTranslations("errors");

  return (
    <div className="flex min-h-svh items-center justify-center p-6">
      <div
        role="alert"
        className="flex w-full max-w-md flex-col items-center gap-4 rounded-xl border border-destructive/30 bg-destructive/5 px-6 py-12 text-center"
      >
        <span className="flex size-12 items-center justify-center rounded-full bg-destructive/10 text-destructive">
          <TriangleAlert className="size-5" />
        </span>
        <div className="space-y-1">
          <p className="font-medium">{t("title")}</p>
          <p className="text-sm text-muted-foreground">{t("description")}</p>
          {error?.digest ? (
            <p className="pt-1 font-mono text-xs text-muted-foreground">{error.digest}</p>
          ) : null}
        </div>
        <Button variant="outline" onClick={reset}>
          <RotateCw className="size-4" />
          {t("retry")}
        </Button>
      </div>
    </div>
  );
}
