"use client";

import { useTranslations } from "next-intl";
import { Server } from "lucide-react";

// Current installed panel version. `branch` is null on an updated panel (a tag
// checkout = detached HEAD), so it's just omitted from the meta line.
export function VersionCard({ installed }) {
  const t = useTranslations("panelUpdate");
  const meta = [installed.commit_short, installed.branch].filter(Boolean).join(" · ");

  return (
    <div className="flex items-center gap-4 rounded-2xl border bg-card p-4">
      <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-muted">
        <Server className="size-5 text-muted-foreground" aria-hidden />
      </span>
      <div className="min-w-0">
        <p className="text-sm text-muted-foreground">{t("installedLabel")}</p>
        <p className="text-lg font-semibold leading-tight">
          {installed.version ? t("versionValue", { version: installed.version }) : t("versionUnknown")}
        </p>
        {meta ? <p className="mt-0.5 truncate font-mono text-xs text-muted-foreground">{meta}</p> : null}
      </div>
    </div>
  );
}
