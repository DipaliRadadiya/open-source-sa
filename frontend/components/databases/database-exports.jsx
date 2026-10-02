"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations, useFormatter } from "next-intl";
import {
  Download,
  HardDriveDownload,
  Loader2,
  Trash2,
  TriangleAlert,
} from "lucide-react";
import { createExport, getExports, deleteExport } from "@/lib/api/databases";
import { getLiveMetrics } from "@/lib/api/server-metrics";
import { formatBytes } from "@/lib/format/bytes";
import { exportSchema, exportsResponseSchema } from "@/lib/schemas/database";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { LoadFailed } from "@/components/data-table/load-failed";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";

const POLL_MS = 3000;

// Above this a dump can take minutes and lands on the database's own disk, so it is confirmed first.
const CONFIRM_ABOVE_BYTES = 512 * 1024 * 1024;
const IN_FLIGHT = ["queued", "running"];

// Past this a dump is flagged as slow; well under the server's abandonment window.
const SLOW_AFTER_MS = 120_000;

const TONE = {
  completed: "success",
  failed: "destructive",
  running: "muted",
  queued: "muted",
};

// Polling stops as soon as nothing is in flight.
export function DatabaseExports({ database, exports: initial = [], canManage, read = null }) {
  const t = useTranslations("databases.exports");
  const router = useRouter();
  const { refreshAndWait } = useRefresh();
  const [polled, setPolled] = useState(null);
  // Polled rows override the server render until it changes (e.g. after `router.refresh()`).
  // Reacts to the prop change during render, not in an effect.
  const [seenInitial, setSeenInitial] = useState(initial);
  if (seenInitial !== initial) {
    setSeenInitial(initial);
    setPolled(null);
  }
  const [starting, setStarting] = useState(false);
  const [deleting, setDeleting] = useState(null);
  const [pendingDelete, setPendingDelete] = useState(false);
  // Asked only for large dumps; free space is fetched only then.
  const [confirming, setConfirming] = useState(false);
  // Past SLOW_AFTER_MS a hint shows. The server closes the row once its queue
  // lock has expired; until then a large dump may legitimately still run.
  const [slow, setSlow] = useState(false);
  const [freeBytes, setFreeBytes] = useState(null);
  const format = useFormatter();

  const all = polled ?? initial;
  // Rows keep a copied database name, so a dump outlives its database. Match on
  // id while the database exists.
  const rows = all.filter((row) => row.database_id === database.id);
  const inFlight = rows.some((row) => IN_FLIGHT.includes(row.status));

  // Only files still on disk: failed runs wrote nothing, and hand-deleted files
  // are `available: false`.
  const kept = rows.reduce(
    (total, row) =>
      row.status === "completed" && row.available
        ? { count: total.count + 1, bytes: total.bytes + (row.size_bytes ?? 0) }
        : total,
    { count: 0, bytes: 0 },
  );

  useEffect(() => {
    if (!inFlight) return;

    const controller = new AbortController();
    const startedAt = Date.now();
    const id = setInterval(async () => {
      if (Date.now() - startedAt > SLOW_AFTER_MS) setSlow(true);
      try {
        const { data } = await getExports({ signal: controller.signal });
        const parsed = exportsResponseSchema.safeParse(data);
        if (!parsed.success) return;

        const still = parsed.data.exports.some(
          (row) => row.database_id === database.id && IN_FLIGHT.includes(row.status),
        );
        // Kept even when finished: falling back to `initial` reverted a completed export to "Waiting".
        setPolled(parsed.data.exports);

        if (!still) {
          setSlow(false);
          router.refresh();
        }
      } catch {
        // A dropped poll isn't worth reporting; the next one runs in 3s.
      }
    }, POLL_MS);

    return () => {
      controller.abort();
      clearInterval(id);
    };
  }, [inFlight, database.id, router]);

  const big = (database.size_bytes ?? 0) > CONFIRM_ABOVE_BYTES;

  async function ask() {
    setConfirming(true);
    setFreeBytes(null);
    try {
      const metrics = await getLiveMetrics();
      setFreeBytes(metrics?.disk?.free ?? null);
    } catch {
      // The dialog omits the free-space line rather than guessing or blocking.
    }
  }

  async function start() {
    setConfirming(false);
    setStarting(true);
    try {
      // The 202 carries the queued row; show it immediately rather than waiting
      // for the refresh round trip.
      const { data } = await createExport(database.id);
      const created = exportSchema.safeParse(data?.export);
      if (created.success) setPolled((current) => [created.data, ...(current ?? initial)]);
      await refreshAndWait();
      toast.success(t("started"));
    } catch (error) {
      toast.error(apiMessage(error, t("startFailed")));
    } finally {
      setStarting(false);
    }
  }

  async function remove() {
    setPendingDelete(true);
    try {
      await deleteExport(deleting.id);
      await refreshAndWait();
      toast.success(t("deleted"));
      setDeleting(null);
    } catch (error) {
      toast.error(apiMessage(error, t("deleteFailed")));
    } finally {
      setPendingDelete(false);
    }
  }

  return (
    <>
      <Card className="gap-0 overflow-hidden py-0">
        {/* flex-wrap plus a minimum width on the text, so the button drops to
            its own row instead of squeezing the sentence. */}
        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
          <div className="flex min-w-40 flex-1 items-center gap-2.5">
            <span className="flex shrink-0 items-center justify-center text-muted-foreground">
              <HardDriveDownload className="size-3.5" />
            </span>
            <div>
              <h2 className="text-base font-semibold tracking-tight">
                {t("title")}
              </h2>
              <p className="text-sm text-muted-foreground">{t("description")}</p>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <RefreshButton />
            <ReasonTooltip
              reason={
                !canManage ? t("noPermission") : inFlight ? t("alreadyRunning") : null
              }
            >
              {/* The button shows progress itself, since that is where the user looks. */}
              <Button
                disabled={!canManage || inFlight || starting}
                onClick={big ? ask : start}
              >
                {starting || inFlight ? (
                  <Loader2 className="size-4 animate-spin" />
                ) : (
                  <HardDriveDownload className="size-4" />
                )}
                {inFlight ? t("inProgress") : t("action")}
              </Button>
            </ReasonTooltip>
          </div>
        </div>

        <CardContent className="px-5 py-0">
          {/* Until a poll succeeds, a failed page read is the only answer. */}
          {read?.failed && polled === null ? (
            <div className="py-5">
              <LoadFailed
                description={t("loadFailed")}
                status={read.status}
                failure={read.failure}
                message={read.message}
              />
            </div>
          ) : rows.length === 0 ? (
            <div className="py-8 text-center">
              <p className="text-sm text-muted-foreground">{t("empty")}</p>
            </div>
          ) : (
            <div className="divide-y">
              {rows.map((row) => (
                <ExportRow
                  key={row.id}
                  row={row}
                  canManage={canManage}
                  onDelete={() => setDeleting(row)}
                  slow={slow}
                />
              ))}
            </div>
          )}
        </CardContent>

        {/* Exports sit on the same disk as the database, so they are not a backup. */}
        <div className="border-t bg-muted/30 px-5 py-3">
          {/* Nothing prunes exports, so the disk usage is shown once files exist. */}
          {kept.count > 0 ? (
            <p className="mb-1 text-xs font-medium">
              {t("diskUsed", {
                count: kept.count,
                size: formatBytes(kept.bytes, format),
              })}
            </p>
          ) : null}
          <p className="text-xs leading-relaxed text-muted-foreground">
            {t("sameDiskWarning")}
          </p>
        </div>
      </Card>

      <ConfirmDialog
        open={confirming}
        onOpenChange={(next) => !next && setConfirming(false)}
        icon={HardDriveDownload}
        tone="warning"
        title={t("confirmTitle", { name: database.name })}
        description={t("confirmDescription", {
          size: database.size_human ?? "",
        })}
        cancelLabel={t("cancel")}
        confirmLabel={starting ? t("starting") : t("action")}
        pending={starting}
        onConfirm={start}
      >
        {freeBytes !== null ? (
          <p className="text-sm text-muted-foreground">
            {t("freeSpace", { free: formatBytes(freeBytes, format) })}
          </p>
        ) : null}
      </ConfirmDialog>

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(next) => !next && setDeleting(null)}
        icon={TriangleAlert}
        tone="destructive"
        title={t("deleteTitle")}
        description={t("deleteDescription")}
        cancelLabel={t("cancel")}
        confirmLabel={pendingDelete ? t("deleting") : t("deleteSubmit")}
        pending={pendingDelete}
        onConfirm={remove}
      />
    </>
  );
}

function ExportRow({ row, canManage, onDelete, slow = false }) {
  const t = useTranslations("databases.exports");
  const running = IN_FLIGHT.includes(row.status);

  return (
    // No wrap: on a phone the actions would drop onto their own line.
    <div className="flex items-start justify-between gap-3 py-3.5">
      <div className="min-w-0 space-y-1">
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant={TONE[row.status] ?? "muted"} className="font-normal">
            {running ? <Loader2 className="size-3 animate-spin" /> : null}
            {t(`status.${row.status}`)}
          </Badge>
          {/* Exact date on hover; relative time cannot be compared. */}
          <Tooltip>
            <TooltipTrigger asChild>
              <span className="cursor-help text-sm text-muted-foreground underline decoration-dotted underline-offset-4">
                {row.finished_at_human ?? row.created_at_human}
              </span>
            </TooltipTrigger>
            <TooltipContent>{row.finished_at ?? row.created_at}</TooltipContent>
          </Tooltip>
          {row.size_human ? (
            <span className="text-sm tabular-nums text-muted-foreground">
              {row.size_human}
            </span>
          ) : null}
        </div>

        {/* Only after SLOW_AFTER_MS, so normal large dumps do not look troubled. */}
        {running && slow ? (
          <p className="text-xs text-muted-foreground">{t("takingLonger")}</p>
        ) : null}

        {/* The server's failure wording plus the id support asks for. */}
        {row.status === "failed" ? (
          <p className="text-xs leading-relaxed text-destructive">
            {row.message ?? t("failedFallback")}
            {row.reference ? (
              <span className="mt-0.5 block font-mono break-all">
                {row.reference}
              </span>
            ) : null}
          </p>
        ) : null}

        {/* The row survives a hand-deleted file; say so instead of a 404 link. */}
        {row.status === "completed" && !row.available ? (
          <p className="text-xs text-muted-foreground">{t("fileGone")}</p>
        ) : null}
      </div>

      <div className="flex shrink-0 items-center gap-1">
        {/* Manage only: the download route requires `database,manage`. */}
        {canManage && row.download_url && row.available ? (
          <Tooltip>
            <TooltipTrigger asChild>
              <Button asChild variant="ghost" size="icon" className="size-8">
                {/* A plain anchor: this is a file stream from the API, not an app page. */}
                <a href={row.download_url} download aria-label={t("download")}>
                  <Download className="size-4" />
                </a>
              </Button>
            </TooltipTrigger>
            <TooltipContent>{t("download")}</TooltipContent>
          </Tooltip>
        ) : null}

        {canManage && !running ? (
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                variant="ghost"
                size="icon"
                aria-label={t("delete")}
                className="size-8 text-destructive hover:bg-destructive/10 hover:text-destructive"
                onClick={onDelete}
              >
                <Trash2 className="size-4" />
              </Button>
            </TooltipTrigger>
            <TooltipContent>{t("delete")}</TooltipContent>
          </Tooltip>
        ) : null}
      </div>
    </div>
  );
}
