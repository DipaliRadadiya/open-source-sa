import { getFormatter, getTranslations } from "next-intl/server";
import { Badge } from "@/components/ui/badge";
import {
  Card,
  CardAction,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { parseApiWallClock } from "@/lib/format/api-date";
import { formatBytes } from "@/lib/format/bytes";

// The API's status vocabulary is undocumented, so anything that is not an
// explicit success is flagged: a false alarm beats a silently failing schedule.
function failed(status) {
  return Boolean(status) && !["success", "completed", "ok", "done"].includes(status);
}

/** Cleanup history, a page at a time; rendered only when there is history. */
export async function RunsCard({ runs, meta, categories, keepSupported = true }) {
  if (!runs?.length) return null;
  const [t, format] = await Promise.all([getTranslations("diskCleaner"), getFormatter()]);
  const labelFor = (key) => categories.find((c) => c.key === key)?.label ?? key;
  const triggerOf = (run) => (t.has(`runs.trigger.${run.trigger}`) ? t(`runs.trigger.${run.trigger}`) : run.trigger);
  // The keep choice the run used; runs from before it existed did each category's default.
  // An older API records no choice: label nothing rather than call every run "as before".
  const keepOf = (run) =>
    !keepSupported ? null : run.keep_days == null ? t("keep.defaultAsBefore") : run.keep_days === 0 ? t("keep.deleteAll") : t("keep.days", { count: run.keep_days });
  const when = (at) => format.dateTime(at, { dateStyle: "medium", timeStyle: "short", timeZone: "UTC" });

  // Each item the run cleaned, with what it freed (`freed` is `[]` from PHP when empty).
  function Cleaned({ run }) {
    const freed = run.freed && !Array.isArray(run.freed) ? run.freed : {};
    return (
      <ul className="flex flex-wrap gap-x-3 gap-y-1 text-sm">
        {(run.categories ?? []).map((key) => (
          <li key={key} className="inline-flex items-baseline gap-1.5">
            <span>{labelFor(key)}</span>
            {freed[key] != null ? (
              <span className="text-xs tabular-nums text-muted-foreground">{formatBytes(freed[key], format)}</span>
            ) : null}
          </li>
        ))}
      </ul>
    );
  }

  return (
    // Rows reach the card edges, like the cleanup list above.
    <Card className="gap-0 overflow-hidden pb-0">
      <CardHeader className="pb-4">
        <CardTitle className="text-base font-semibold">{t("runs.title")}</CardTitle>
        <CardDescription>{t("runs.subtitle")}</CardDescription>
        <CardAction>
          <RefreshButton />
        </CardAction>
      </CardHeader>

      {/* A table, not name-left/size-right rows: the API sends what each run cleaned
          and freed, which filled the empty middle (Krishna, 13 Oct). */}
      {/* Phone: one stacked block per run; five columns do not fit 390px. */}
      <ul className="divide-y border-t md:hidden">
        {runs.map((run) => {
          const at = parseApiWallClock(run.created_at);
          return (
            <li key={run.id} className="space-y-1.5 px-4 py-3">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="text-sm">{run.created_at_human ?? run.created_at}</p>
                  {at ? <p className="text-xs text-muted-foreground">{when(at)}</p> : null}
                </div>
                <span className="inline-flex shrink-0 items-center gap-2">
                  {failed(run.status) ? <Badge variant="destructive" className="font-normal">{t("runs.failed")}</Badge> : null}
                  <Badge variant="outline" className="font-normal">{triggerOf(run)}</Badge>
                  {keepOf(run) ? <span className="text-xs text-muted-foreground">{keepOf(run)}</span> : null}
                  <span className="text-sm font-medium tabular-nums">{run.freed_total_human ?? "0 B"}</span>
                </span>
              </div>
              <Cleaned run={run} />
            </li>
          );
        })}
      </ul>

      <div className="hidden border-t md:block">
        <Table>
          <TableHeader>
            <TableRow className="bg-muted/40 hover:bg-muted/40">
              <TableHead>{t("runs.colWhen")}</TableHead>
              <TableHead>{t("runs.colWhat")}</TableHead>
              <TableHead className="hidden md:table-cell">{t("runs.colHow")}</TableHead>
              <TableHead className="hidden lg:table-cell">{t("runs.colDiskAfter")}</TableHead>
              <TableHead className="text-right">{t("runs.colFreed")}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {runs.map((run) => {
              const at = parseApiWallClock(run.created_at);
              const trigger = triggerOf(run);
              return (
                <TableRow key={run.id}>
                  <TableCell className="align-top">
                    <p>{run.created_at_human ?? run.created_at}</p>
                    {/* The server's own clock, read as written (no zone shift). */}
                    {at ? <p className="text-xs text-muted-foreground">{when(at)}</p> : null}
                  </TableCell>
                  <TableCell className="align-top whitespace-normal">
                    <Cleaned run={run} />
                  </TableCell>
                  <TableCell className="hidden align-top md:table-cell">
                    <Badge variant="outline" className="font-normal">{trigger}</Badge>
                    {keepOf(run) ? <p className="mt-1 text-xs text-muted-foreground">{keepOf(run)}</p> : null}
                  </TableCell>
                  <TableCell className="hidden align-top tabular-nums text-muted-foreground lg:table-cell">
                    {run.disk_percent != null ? t("summary.percentUsed", { percent: Math.round(run.disk_percent) }) : "—"}
                  </TableCell>
                  <TableCell className="align-top text-right">
                    {/* A crashed run and an empty run both freed "0 B"; flag the crash. */}
                    <span className="inline-flex items-center justify-end gap-2">
                      {failed(run.status) ? (
                        <Badge variant="destructive" className="font-normal">{t("runs.failed")}</Badge>
                      ) : null}
                      <span className="font-medium tabular-nums">{run.freed_total_human ?? "0 B"}</span>
                    </span>
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </div>

      {meta && meta.last_page > 1 ? (
        <div className="border-t px-4 py-3">
          <DataTablePagination meta={meta} />
        </div>
      ) : null}
    </Card>
  );
}
