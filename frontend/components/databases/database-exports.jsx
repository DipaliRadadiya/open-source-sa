"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations, useFormatter } from "next-intl";
import {
  Download,
  FileArchive,
  HardDriveDownload,
  Loader2,
  Trash2,
  TriangleAlert,
} from "lucide-react";
import { EmptyState } from "@/components/data-table/empty-state";
import { createExport, getExports, deleteExport } from "@/lib/api/databases";
import { getLiveMetrics } from "@/lib/api/server-metrics";
import { formatBytes } from "@/lib/format/bytes";
import { exportSchema, exportsResponseSchema } from "@/lib/schemas/database";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { LoadFailed } from "@/components/data-table/load-failed";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { CardIcon } from "@/components/ui/card-icon";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { DENIED, ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { SizeText } from "@/components/ui/size-text";
import { localizeSizeText } from "@/lib/format/bytes";

const POLL_MS = 3000;

const CONFIRM_ABOVE_BYTES = 512 * 1024 * 1024;
const IN_FLIGHT = ["queued", "running"];

const ROWS_SHOWN = 5;

const SLOW_AFTER_MS = 120_000;

const TONE = {
  completed: "success",
  failed: "destructive",
  running: "muted",
  queued: "muted",
};

export function DatabaseExports({ database, exports: initial = [], canManage, read = null }) {
  const t = useTranslations("databases.exports");
  const router = useRouter();
  const { refreshAndWait } = useRefresh();
  const [polled, setPolled] = useState(null);
  const [seenInitial, setSeenInitial] = useState(initial);
  if (seenInitial !== initial) {
    setSeenInitial(initial);
    setPolled(null);
  }
  const [starting, setStarting] = useState(false);
  const [deleting, setDeleting] = useState(null);
  const [pendingDelete, setPendingDelete] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [slow, setSlow] = useState(false);
  const [freeBytes, setFreeBytes] = useState(null);
  const [showAll, setShowAll] = useState(false);
  const format = useFormatter();

  const all = polled ?? initial;
  const rows = all.filter((row) => row.database_id === database.id);
  const inFlight = rows.some((row) => IN_FLIGHT.includes(row.status));

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
        setPolled(parsed.data.exports);

        if (!still) {
          setSlow(false);
          router.refresh();
        }
      } catch {
        // retry on the next poll
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
      // retry on the next poll
    }
  }

  async function start() {
    setConfirming(false);
    setStarting(true);
    try {
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
        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
          <div className="flex min-w-40 flex-1 items-center gap-3">
            <CardIcon icon={FileArchive} />
            <div>
              <h2 className="text-[15px] font-semibold tracking-tight">
                {t("title")}
              </h2>
              <p className="text-sm text-muted-foreground">{t("description")}</p>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <RefreshButton />
            <ReasonTooltip
              reason={
                !canManage ? DENIED : inFlight ? t("alreadyRunning") : null
              }
            >
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
            <div className="py-5">
              <EmptyState compact icon={HardDriveDownload} badge={null} title={t("empty")} />
            </div>
          ) : (
            <div className="divide-y">
              {(showAll ? rows : rows.slice(0, ROWS_SHOWN)).map((row) => (
                <ExportRow
                  key={row.id}
                  row={row}
                  canManage={canManage}
                  onDelete={() => setDeleting(row)}
                  slow={slow}
                />
              ))}
              {rows.length > ROWS_SHOWN ? (
                <div className="py-2.5">
                  <Button variant="link" size="sm" className="h-auto px-0" onClick={() => setShowAll((v) => !v)}>
                    {showAll ? t("showLess") : t("showAll", { count: rows.length })}
                  </Button>
                </div>
              ) : null}
            </div>
          )}
        </CardContent>

        <div className="border-t bg-muted/30 px-5 py-3">
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
          size: localizeSizeText(database.size_human ?? "", format),
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
    <div className="flex items-start justify-between gap-3 py-3.5">
      <div className="min-w-0 space-y-1">
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant={TONE[row.status] ?? "muted"} className="font-normal">
            {running ? <Loader2 className="size-3 animate-spin" /> : null}
            {t(`status.${row.status}`)}
          </Badge>
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
              <SizeText value={row.size_human} />
            </span>
          ) : null}
          {row.purpose === "import_safety" ? (
            <Badge variant="outline" className="font-normal">
              {t("importSafety")}
            </Badge>
          ) : null}
        </div>

        {running && slow ? (
          <p className="text-xs text-muted-foreground">{t("takingLonger")}</p>
        ) : null}

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

        {row.status === "completed" && !row.available ? (
          <p className="text-xs text-muted-foreground">{t("fileGone")}</p>
        ) : null}
      </div>

      <div className="flex shrink-0 items-center gap-1">
        {canManage && row.download_url && row.available ? (
          <Tooltip>
            <TooltipTrigger asChild>
              <Button asChild variant="ghost" size="icon" className="size-8">
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
