import { getTranslations } from "next-intl/server";
import { Badge } from "@/components/ui/badge";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";

/**
 * What cleaning has actually done lately.
 *
 * Rendered only when there is history: an empty "Recent cleanups" card on a
 * fresh server is a heading with nothing under it, which the panel has been
 * bitten by before.
 */
// The API has not documented its status vocabulary, so treat anything that is
// not an explicit success as suspect rather than guessing the failure words —
// a wrongly-flagged run is recoverable, a silently-failing schedule is not.
function failed(status) {
  return Boolean(status) && !["success", "completed", "ok", "done"].includes(status);
}

export async function RunsCard({ runs }) {
  if (!runs?.length) return null;
  const t = await getTranslations("diskCleaner");

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base font-semibold">{t("runs.title")}</CardTitle>
        <CardDescription>{t("runs.subtitle")}</CardDescription>
      </CardHeader>

      <CardContent>
        <ul className="divide-y rounded-lg border">
          {runs.map((run) => (
            <li key={run.id} className="flex items-center justify-between gap-4 px-3 py-2.5">
              <div className="min-w-0 space-y-0.5">
                <p className="text-sm">{run.created_at_human ?? run.created_at}</p>
                <p className="truncate text-xs text-muted-foreground">
                  {t("runs.categories", { count: run.categories?.length ?? 0 })}
                </p>
              </div>

              <div className="flex shrink-0 items-center gap-2">
                {/* A run that crashed and a run that found nothing both freed
                    "0 B". Without this the list quietly reports a broken
                    schedule as a working one. */}
                {failed(run.status) ? (
                  <Badge variant="destructive" className="font-normal">
                    {t("runs.failed")}
                  </Badge>
                ) : null}

                {/* Manual or automatic — the only reason to look at this list is
                    usually "did the schedule actually run?". */}
                <Badge variant="outline" className="font-normal">
                  {t.has(`runs.trigger.${run.trigger}`)
                    ? t(`runs.trigger.${run.trigger}`)
                    : run.trigger}
                </Badge>
                <span className="text-sm font-medium tabular-nums">
                  {run.freed_total_human ?? "0 B"}
                </span>
              </div>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
