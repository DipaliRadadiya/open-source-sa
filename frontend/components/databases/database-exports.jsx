"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
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
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";

const POLL_MS = 3000;

/**
 * Below this a dump is not worth a question — it finishes in seconds and the
 * disk will not notice. Above it, the file lands on the same disk as the
 * database and can take minutes.
 */
const CONFIRM_ABOVE_BYTES = 512 * 1024 * 1024;
const IN_FLIGHT = ["queued", "running"];

// Past this a dump is worth commenting on. Well under the server's own
// abandonment window, so the hint appears while there is still something to
// wait for rather than moments before the row fails anyway.
const SLOW_AFTER_MS = 120_000;

const TONE = {
  completed: "success",
  failed: "destructive",
  running: "secondary",
  queued: "secondary",
};

/**
 * Dumps of this database.
 *
 * The work is queued, so a row appears the instant the button is pressed and
 * fills in as it goes — the alternative is a button that looks broken for the
 * minute a real dump takes. Polling stops as soon as nothing is in flight.
 */
export function DatabaseExports({ database, exports: initial = [], canManage }) {
  const t = useTranslations("databases.exports");
  const router = useRouter();
  const [polled, setPolled] = useState(null);
  const [starting, setStarting] = useState(false);
  const [deleting, setDeleting] = useState(null);
  const [pendingDelete, setPendingDelete] = useState(false);
  // Only asked for when the dump is big enough to matter, and only then is the
  // free-space figure fetched — the page should not pay for it otherwise.
  const [confirming, setConfirming] = useState(false);
  // A dump that has run past this is not necessarily broken — a large database
  // legitimately takes minutes — but a bare spinner cannot say either. The row
  // is closed out server-side once its queue lock has provably expired; until
  // then this is the difference between "working" and "nothing is coming".
  const [slow, setSlow] = useState(false);
  const [freeBytes, setFreeBytes] = useState(null);
  const format = useFormatter();

  const all = polled ?? initial;
  // Rows keep a copied database name, so a dump outlives its database. Match on
  // id while the database exists.
  const rows = all.filter((row) => row.database_id === database.id);
  const inFlight = rows.some((row) => IN_FLIGHT.includes(row.status));

  // Only files that are still on disk: a failed run wrote nothing, and a row
  // whose file was deleted by hand is `available: false` and costs no space.
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
        if (still) {
          setPolled(parsed.data.exports);
        } else {
          // Finished: hand back to the server render so the row's final size
          // and download link come from the same place as everything else.
          setPolled(null);
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
      // Without it the dialog simply omits the free-space line rather than
      // guessing or blocking.
    }
  }

  async function start() {
    setConfirming(false);
    setStarting(true);
    try {
      // The 202 carries the queued row itself — it is created before the job is
      // dispatched. Showing it straight away is the difference between "a dump
      // is running" and a screen that looks like the click did nothing: the
      // POST returns in milliseconds, `starting` goes false with it, and the
      // refresh that would have brought the row takes a server round trip to
      // land. In that gap the button was idle again and the list unchanged.
      const { data } = await createExport(database.id);
      const created = exportSchema.safeParse(data?.export);
      if (created.success) setPolled((current) => [created.data, ...(current ?? initial)]);
      toast.success(t("started"));
      router.refresh();
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
      toast.success(t("deleted"));
      setDeleting(null);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("deleteFailed")));
    } finally {
      setPendingDelete(false);
    }
  }

  return (
    <>
      <Card className="gap-0 overflow-hidden py-0">
        {/* flex-wrap + a real minimum on the text: without them the
            heading block shrank to make room for the button and the sentence
            squeezed to three words a line. The button drops to its own row
            instead. */}
        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
          <div className="flex min-w-40 flex-1 items-center gap-2.5">
            <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
              <HardDriveDownload className="size-3.5" />
            </span>
            <div>
              <h2 className="text-base font-semibold tracking-tight">
                {t("title")}
              </h2>
              <p className="text-sm text-muted-foreground">{t("description")}</p>
            </div>
          </div>

          <ReasonTooltip
            reason={
              !canManage ? t("noPermission") : inFlight ? t("alreadyRunning") : null
            }
          >
            {/* The button says what is happening rather than just going grey.
                A dump runs for minutes and the only other sign of it was a
                small badge at the bottom of the card, which is not where
                anyone is looking after pressing this. */}
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

        <CardContent className="px-5 py-0">
          {rows.length === 0 ? (
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

        {/* Said plainly, because "export" still sounds like safety to most
            people and this one cannot give it on its own: the dump sits on the
            same disk as the database it came from. */}
        <div className="border-t bg-muted/30 px-5 py-3">
          {/* How much of that disk these files are actually using, and that
              they are nobody else's job. Nothing prunes exports — no age
              limit, no count cap, no scheduled sweep — so ten dumps of a 2GB
              database is 20GB gone quietly. Only shown once files exist:
              before that there is nothing to warn about. */}
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
    // Same reason as the user rows: wrapping dropped the row actions onto a
    // line of their own on a phone, orphaned from the row they belong to.
    <div className="flex items-start justify-between gap-3 py-3.5">
      <div className="min-w-0 space-y-1">
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant={TONE[row.status] ?? "secondary"} className="font-normal">
            {running ? <Loader2 className="size-3 animate-spin" /> : null}
            {t(`status.${row.status}`)}
          </Badge>
          {/* Relative time reads well but cannot be compared. Picking the
              right dump out of several needs the actual date, so it hangs off
              the hover rather than crowding the row. */}
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

        {/* Said only once it has been a while: a dump legitimately takes
            minutes on a large database, and saying so immediately would make
            every export look troubled. The server closes the row out once its
            queue lock has provably expired, so this covers the gap between
            "slow" and "already failed and nobody has noticed yet". */}
        {running && slow ? (
          <p className="text-xs text-muted-foreground">{t("takingLonger")}</p>
        ) : null}

        {/* The server's own wording for a failure, plus the id support asks
            for. Ours would be a guess about something we did not witness. */}
        {row.status === "failed" ? (
          <p className="text-xs leading-relaxed text-destructive">
            {row.message ?? t("failedFallback")}
            {row.reference ? (
              <span className="mt-0.5 block font-mono break-all opacity-80">
                {row.reference}
              </span>
            ) : null}
          </p>
        ) : null}

        {/* The row survives the file being deleted from disk by hand — saying
            so beats a download link that 404s. */}
        {row.status === "completed" && !row.available ? (
          <p className="text-xs text-muted-foreground">{t("fileGone")}</p>
        ) : null}
      </div>

      <div className="flex shrink-0 items-center gap-1">
        {row.download_url && row.available ? (
          <Tooltip>
            <TooltipTrigger asChild>
              <Button asChild variant="ghost" size="icon" className="size-8">
                {/* A plain anchor, not a router link: this is a file stream
                    from the API, not a page in the app. */}
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
