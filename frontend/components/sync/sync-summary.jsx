import { CircleAlert, CircleCheck, Eye, Loader2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { runTotals } from "@/lib/server/sync-selection";
import { cn } from "@/lib/utils";

/**
 * What this run was, and what it did. Preview and apply share this screen, so
 * the preview banner is what states that nothing has been written yet.
 */
export function SyncSummary({ run, loaded, running }) {
  const t = useTranslations("sync");
  const totals = runTotals(run.totals);
  const preview = run.mode === "preview";

  /* Failures lead: they are the only rows anyone needs to act on. */
  const failed = totals.failed > 0;

  const tone = running ? "running" : failed ? "failed" : preview ? "preview" : "done";
  const Icon = { running: Loader2, failed: CircleAlert, preview: Eye, done: CircleCheck }[tone];

  return (
    <div
      className={cn(
        "flex flex-wrap items-center gap-4 rounded-2xl border p-4",
        failed ? "border-destructive/40 bg-destructive/5" : "bg-muted/40",
      )}
    >
      <span
        className={cn(
          "flex size-11 shrink-0 items-center justify-center rounded-xl",
          failed && "bg-destructive/10 text-destructive",
          !failed && tone === "done" && "bg-success/10 text-success",
          !failed && (tone === "preview" || tone === "running") && "bg-primary/10 text-primary",
        )}
      >
        <Icon className={cn("size-6", running && "animate-spin")} aria-hidden />
      </span>

      <div className="min-w-0 flex-1 space-y-1">
        {running ? (
          <>
            <p className="font-medium">
              {preview ? t("summary.scanning") : t("summary.adopting")}
            </p>
            {/* A climbing count, not a percentage: the total is unknown until
                the scan finishes. */}
            <p className="text-sm text-muted-foreground">
              {t("summary.foundSoFar", { count: loaded })}
            </p>
          </>
        ) : failed ? (
          <>
            <p className="font-medium">{t("summary.failed", { count: totals.failed })}</p>
            <p className="text-sm text-muted-foreground">
              {t("summary.counts", {
                adopted: totals.adopted,
                skipped: totals.skipped,
                failed: totals.failed,
              })}
            </p>
          </>
        ) : preview ? (
          <>
            <p className="font-medium">{t("summary.previewTitle", { count: loaded })}</p>
            <p className="text-sm text-muted-foreground">{t("summary.previewNothingChanged")}</p>
          </>
        ) : (
          <>
            <p className="font-medium">{t("summary.adopted", { count: totals.adopted })}</p>
            {totals.skipped ? (
              <p className="text-sm text-muted-foreground">
                {t("summary.skipped", { count: totals.skipped })}
              </p>
            ) : null}
          </>
        )}
      </div>
    </div>
  );
}
