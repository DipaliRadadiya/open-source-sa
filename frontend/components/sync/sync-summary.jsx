import { CircleAlert, CircleCheck, Eye, Loader2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { runTotals } from "@/lib/server/sync-selection";
import { cn } from "@/lib/utils";

/**
 * What this run was, and what it did.
 *
 * The preview banner is not decoration. Every comparable tool that got this
 * right — `terraform plan`, Plesk's "Prepare Migration" — says plainly that
 * nothing has been written yet, and the one that got it wrong (WHM's Transfer
 * Tool) is the one where preview and apply share a screen. Since this panel
 * also shares a screen between the two modes, the banner is the only thing
 * distinguishing "here is what I found" from "here is what I did".
 */
export function SyncSummary({ run, loaded, running }) {
  const t = useTranslations("sync");
  const totals = runTotals(run.totals);
  const preview = run.mode === "preview";

  /* Failures lead. A run that adopted 40 things and failed 2 is not a success
     with a footnote — the 2 are the only rows anyone needs to act on, and a
     green tick over them is how a half-finished import reads as a finished
     one. */
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
            {/* A count that climbs is the honest progress indicator here: the
                backend never says how many things exist before it has looked,
                so a percentage bar would be inventing a denominator. */}
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
