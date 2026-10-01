import { getTranslations } from "next-intl/server";
import { Badge } from "@/components/ui/badge";
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { RefreshButton } from "@/components/data-table/refresh-button";

// The API's status vocabulary is undocumented, so anything that is not an
// explicit success is flagged: a false alarm beats a silently failing schedule.
function failed(status) {
  return Boolean(status) && !["success", "completed", "ok", "done"].includes(status);
}

/** Recent cleanups; rendered only when there is history. */
export async function RunsCard({ runs }) {
  if (!runs?.length) return null;
  const t = await getTranslations("diskCleaner");

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base font-semibold">{t("runs.title")}</CardTitle>
        <CardDescription>{t("runs.subtitle")}</CardDescription>
        <CardAction>
          <RefreshButton />
        </CardAction>
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
                {/* A crashed run and an empty run both freed "0 B"; flag the crash. */}
                {failed(run.status) ? (
                  <Badge variant="destructive" className="font-normal">
                    {t("runs.failed")}
                  </Badge>
                ) : null}

                {/* Manual or automatic: answers "did the schedule run?". */}
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
