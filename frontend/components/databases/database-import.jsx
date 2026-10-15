"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { Check, Circle, Download, FileText, FileUp, Loader2, Play, ShieldCheck, Trash2, TriangleAlert, UploadCloud } from "lucide-react";
import { deleteImport, getImports, startImport, uploadAndStartImport } from "@/lib/api/databases";
import { importSchema, importsResponseSchema } from "@/lib/schemas/database";
import { IMPORT_IN_FLIGHT, IMPORT_STEPS, engineMismatch, importFormat, importStage } from "@/lib/databases/import-formats";
import { formatBytes } from "@/lib/format/bytes";
import { apiMessage } from "@/lib/api/error-message";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { CardIcon } from "@/components/ui/card-icon";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Progress } from "@/components/ui/progress";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { useWatchUnsaved } from "@/components/ui/unsaved-guard";

const POLL_MS = 2500;
// Older imports fold away so the history cannot push the page out of reach.
const HISTORY_SHOWN = 3;

// Failures that stop before anything is dropped, so the data is as it was.
const UNTOUCHED = ["wrong_format", "client_command", "truncated", "unreadable", "backup_failed", "no_account", "database_missing", "abandoned"];

// Brand names, not translated.
const FAMILY_NAME = { sql: "MySQL / MariaDB", pgsql: "PostgreSQL", mongo: "MongoDB" };

const TONE = { completed: "success", failed: "destructive", running: "muted", queued: "muted", uploading: "muted" };

// Upload a dump and replace the database with it. The user confirms before the
// upload, so a two-gigabyte file is not sent only to be declined; the run then
// starts by itself. The server backs the database up first and puts it back if
// the load fails — the card says so before and after.
export function DatabaseImport({ database, imports: initial = [], exports = [], canManage, unavailable = null }) {
  const t = useTranslations("databases.import");
  const tDb = useTranslations("databases");
  const format = useFormatter();
  const router = useRouter();
  const inputRef = useRef(null);
  const controllerRef = useRef(null);
  const [polled, setPolled] = useState(null);
  const [seenInitial, setSeenInitial] = useState(initial);
  if (seenInitial !== initial) {
    setSeenInitial(initial);
    setPolled(null);
  }
  const [dragOver, setDragOver] = useState(false);
  const [showAll, setShowAll] = useState(false);
  const [chosen, setChosen] = useState(null);
  // { file, progress } while the browser is sending the file.
  const [upload, setUpload] = useState(null);
  const [busyRow, setBusyRow] = useState(null);
  const [removing, setRemoving] = useState(null);
  // The run this tab started, so its end gets a toast. A ref: only the poll reads it.
  const watchingRef = useRef(null);

  const rows = polled ?? initial;
  const running = rows.find((row) => IMPORT_IN_FLIGHT.includes(row.status)) ?? null;
  const formats = importFormat(database);
  // An id, not the row: every poll makes a new row object and would restart the interval.
  const runningId = running?.id ?? null;

  // Leaving mid-upload stops it; the browser's own exits are covered too.
  useWatchUnsaved("database-import", upload !== null);

  useEffect(() => {
    if (runningId === null) return;
    const controller = new AbortController();
    const id = setInterval(async () => {
      try {
        const { data } = await getImports(database.id, { signal: controller.signal });
        const parsed = importsResponseSchema.safeParse(data);
        if (!parsed.success) return;
        setPolled(parsed.data.imports);

        // The run this tab started has ended: say how, and refresh so the
        // automatic backup appears in Exports and the table count updates.
        const row = parsed.data.imports.find((r) => r.id === watchingRef.current);
        if (row && (row.status === "completed" || row.status === "failed")) {
          watchingRef.current = null;
          if (row.status === "completed") toast.success(t("completed", { file: row.file_name ?? "", name: database.name }));
          else toast.error(row.message ?? t("failedFallback"));
          router.refresh();
        }
      } catch {
        // The next poll runs shortly.
      }
    }, POLL_MS);
    return () => {
      controller.abort();
      clearInterval(id);
    };
  }, [runningId, database.id, database.name, router, t]);

  const blocked = !canManage ? t("noPermission") : unavailable ? t("engineDown", { engine: unavailable }) : running || upload ? t("busy") : null;

  function choose(files) {
    const file = files?.[0];
    if (!file || blocked) return;
    setChosen(file);
  }

  async function run() {
    const file = chosen;
    setChosen(null);
    const controller = new AbortController();
    controllerRef.current = controller;
    setUpload({ file, progress: 0 });
    try {
      const { data } = await uploadAndStartImport(database.id, file, {
        signal: controller.signal,
        onProgress: (progress) => setUpload({ file, progress }),
        onBegun: (id) => {
          watchingRef.current = id;
        },
      });
      const parsed = importSchema.safeParse(data?.import);
      if (parsed.success) setPolled((current) => [parsed.data, ...(current ?? initial).filter((r) => r.id !== parsed.data.id)]);
      toast.success(t("started"));
    } catch (error) {
      if (controller.signal.aborted) {
        toast.message(t("cancelled"));
        watchingRef.current = null;
      } else {
        toast.error(apiMessage(error, t("uploadFailed")));
      }
      // A refused start leaves an uploaded row behind; show it.
      router.refresh();
    } finally {
      controllerRef.current = null;
      setUpload(null);
    }
  }

  async function startAgain(row) {
    setBusyRow(row.id);
    try {
      const { data } = await startImport(database.id, row.id);
      const parsed = importSchema.safeParse(data?.import);
      if (parsed.success) setPolled((current) => (current ?? initial).map((r) => (r.id === row.id ? parsed.data : r)));
      watchingRef.current = row.id;
      toast.success(t("started"));
    } catch (error) {
      toast.error(apiMessage(error, t("startFailed")));
    } finally {
      setBusyRow(null);
    }
  }

  async function remove() {
    const row = removing;
    setBusyRow(row.id);
    try {
      await deleteImport(database.id, row.id);
      setPolled((current) => (current ?? initial).filter((r) => r.id !== row.id));
      setRemoving(null);
      toast.success(t("removed"));
    } catch (error) {
      toast.error(apiMessage(error, t("removeFailed")));
    } finally {
      setBusyRow(null);
    }
  }

  // The stepper follows the browser while it uploads, then the server's row.
  const stage = upload ? "uploading" : importStage(running);
  const activeFile = upload?.file.name ?? running?.file_name ?? "";
  const activeSize = upload ? formatBytes(upload.file.size, format) : (running?.size_human ?? "");
  const history = rows.filter((row) => row.id !== running?.id);
  // A warning, not a block: the name may be wrong, and a failed run puts the data back.
  const mismatch = chosen ? engineMismatch(chosen.name, database) : null;
  // The backup this import took first, when it is still on disk and downloadable.
  const backupFor = (row) => {
    if (!canManage || !row.safety_export_id) return null;
    const found = exports.find((e) => e.id === row.safety_export_id);
    return found?.status === "completed" && found.available && found.download_url ? found.download_url : null;
  };

  return (
    <>
      <Card className="gap-0 overflow-hidden py-0">
        <div className="flex flex-wrap items-center gap-3 border-b px-5 py-3.5">
          <CardIcon icon={FileUp} />
          <div className="min-w-48 flex-1">
            <h2 className="text-[15px] font-semibold tracking-tight">{t("title")}</h2>
            <p className="text-sm text-muted-foreground">{t("description")}</p>
          </div>
        </div>

        <CardContent className="space-y-4 px-5 py-4">
          {stage ? (
            <div className="space-y-3 rounded-xl border bg-muted/30 p-4">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="min-w-0 text-sm">
                  <span className="font-mono font-medium break-all">{activeFile}</span>{" "}
                  <span className="text-muted-foreground tabular-nums">{activeSize}</span>
                </p>
                {upload ? (
                  <Button variant="outline" size="sm" onClick={() => controllerRef.current?.abort()}>
                    {t("cancelUpload")}
                  </Button>
                ) : null}
              </div>

              <ol className="grid gap-2 sm:grid-cols-4">
                {IMPORT_STEPS.map((step, index) => {
                  const current = IMPORT_STEPS.indexOf(stage);
                  const state = index < current ? "done" : index === current ? "current" : "next";
                  return (
                    <li key={step} className={cn("flex items-center gap-2 text-sm", state === "next" && "text-muted-foreground")}>
                      {state === "done" ? (
                        <Check className="size-4 shrink-0 text-success" aria-hidden />
                      ) : state === "current" ? (
                        <Loader2 className="size-4 shrink-0 animate-spin text-primary" aria-hidden />
                      ) : (
                        <Circle className="size-4 shrink-0" aria-hidden />
                      )}
                      <span className={cn(state === "current" && "font-medium")}>
                        {step === "importing" && running?.step === "rolling_back" ? t("rollingBack") : t(`steps.${step}`)}
                        {step === "uploading" && upload ? ` ${Math.round(upload.progress * 100)}%` : ""}
                      </span>
                    </li>
                  );
                })}
              </ol>

              {upload ? <Progress value={Math.round(upload.progress * 100)} aria-label={t("steps.uploading")} /> : null}

              <p className="text-xs text-muted-foreground">{upload ? t("keepOpen") : t("serverNote")}</p>
            </div>
          ) : (
            // One row: the button is the way in, the whole row also takes a dropped file.
            <div
              onDragOver={(e) => {
                e.preventDefault();
                if (!blocked) setDragOver(true);
              }}
              onDragLeave={() => setDragOver(false)}
              onDrop={(e) => {
                e.preventDefault();
                setDragOver(false);
                choose(e.dataTransfer.files);
              }}
              className={cn(
                "flex flex-wrap items-center gap-3 rounded-xl border-2 border-dashed px-4 py-3 transition-colors",
                blocked && "opacity-60",
                dragOver && "border-primary bg-primary/5",
              )}
            >
              <UploadCloud className="size-5 shrink-0 text-muted-foreground" aria-hidden />
              <div className="min-w-48 flex-1">
                <p className="text-sm font-medium">{t("drop")}</p>
                <p className="text-xs text-muted-foreground">{t(`formats.${formats.key}`)}</p>
              </div>
              <ReasonTooltip reason={blocked}>
                <Button variant="outline" size="sm" disabled={Boolean(blocked)} onClick={() => inputRef.current?.click()}>
                  <FileUp className="size-4" />
                  {t("choose")}
                </Button>
              </ReasonTooltip>
              <input
                ref={inputRef}
                type="file"
                accept={formats.accept}
                // Hidden, not sr-only: screen readers announced it as a second "Choose file".
                className="hidden"
                tabIndex={-1}
                onChange={(e) => {
                  choose(e.target.files);
                  e.target.value = "";
                }}
              />
            </div>
          )}

          {history.length ? (
            <div className="space-y-2">
              <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">{t("history")}</p>
              <div className="divide-y rounded-xl border">
                {(showAll ? history : history.slice(0, HISTORY_SHOWN)).map((row) => (
                  <ImportRow
                    key={row.id}
                    row={row}
                    canManage={canManage}
                    busy={busyRow === row.id}
                    // A finished upload whose start was refused can be started again.
                    canStart={canManage && !running && !upload && row.status === "uploading" && row.received_bytes === row.size_bytes}
                    onStart={() => startAgain(row)}
                    onRemove={() => setRemoving(row)}
                    backup={backupFor(row)}
                  />
                ))}
              </div>
              {history.length > HISTORY_SHOWN ? (
                <Button variant="link" size="sm" className="h-auto px-0" onClick={() => setShowAll((v) => !v)}>
                  {showAll ? t("showLess") : t("showAll", { count: history.length })}
                </Button>
              ) : null}
            </div>
          ) : null}
        </CardContent>

        <div className="border-t bg-muted/30 px-5 py-3">
          <p className="text-xs leading-relaxed text-muted-foreground">{t("backupNote")}</p>
        </div>
      </Card>

      <ConfirmDialog
        open={chosen !== null}
        onOpenChange={(next) => !next && setChosen(null)}
        icon={TriangleAlert}
        tone="destructive"
        confirmVariant="destructive"
        title={t("confirmTitle", { name: database.name })}
        // Short: one sentence, then the file on its own, then the safety net (Krishna, 15 Oct:
        // one paragraph with the long file name in it was too much to read).
        description={t("confirmDescription")}
        cancelLabel={t("cancel")}
        confirmLabel={t("confirmLabel")}
        onConfirm={run}
      >
        {chosen ? (
          <>
            <div className="flex items-start gap-3 rounded-lg border bg-muted/40 px-3 py-2.5">
              <FileText className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
              <div className="min-w-0">
                <p className="font-mono text-sm break-all">{chosen.name}</p>
                <p className="text-xs text-muted-foreground tabular-nums">{formatBytes(chosen.size, format)}</p>
              </div>
            </div>
            {mismatch ? (
              <p className="flex items-start gap-2 text-sm text-warning">
                <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
                {t("mismatch", { fileEngine: FAMILY_NAME[mismatch], engine: tDb(`engines.${database.engine}`) })}
              </p>
            ) : null}
            <p className="flex items-start gap-2 text-sm text-muted-foreground">
              <ShieldCheck className="mt-0.5 size-4 shrink-0 text-success" aria-hidden />
              {t("confirmSafety")}
            </p>
          </>
        ) : null}
      </ConfirmDialog>

      <ConfirmDialog
        open={removing !== null}
        onOpenChange={(next) => !next && setRemoving(null)}
        icon={Trash2}
        tone="destructive"
        title={t("removeTitle")}
        description={t("removeDescription")}
        cancelLabel={t("cancel")}
        confirmLabel={t("remove")}
        pending={removing !== null && busyRow === removing.id}
        onConfirm={remove}
      />
    </>
  );
}

function ImportRow({ row, canManage, busy, canStart, onStart, onRemove, backup = null }) {
  const t = useTranslations("databases.import");
  // Safe: nothing was dropped, or the backup was put back. Otherwise the backup is in Exports.
  const safe = row.restored || UNTOUCHED.includes(row.reason);

  return (
    <div className="flex items-start justify-between gap-3 px-4 py-3">
      <div className="min-w-0 space-y-1">
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant={TONE[row.status] ?? "muted"} className="font-normal">
            {t(`status.${row.status}`)}
          </Badge>
          <span className="min-w-0 font-mono text-sm break-all">{row.file_name}</span>
          {row.size_human ? <span className="text-sm tabular-nums text-muted-foreground">{row.size_human}</span> : null}
        </div>
        <p className="text-xs text-muted-foreground">
          {[row.requested_by ? t("by", { user: row.requested_by.username }) : null, row.finished_at_human ?? row.created_at_human]
            .filter(Boolean)
            .join(" · ")}
        </p>
        {row.status === "uploading" && row.received_bytes !== row.size_bytes ? (
          <p className="text-xs text-muted-foreground">{t("uploadUnfinished")}</p>
        ) : null}
        {row.status === "failed" ? (
          <p className={cn("text-xs leading-relaxed", safe ? "text-warning" : "text-destructive")}>
            {row.message ?? t("failedFallback")}
            {row.reference ? <span className="mt-0.5 block font-mono break-all">{row.reference}</span> : null}
          </p>
        ) : null}
      </div>

      <div className="flex shrink-0 items-center gap-1">
        {/* Matters most when a failed import could not put the data back. */}
        {backup ? (
          <Tooltip>
            <TooltipTrigger asChild>
              <Button asChild variant="ghost" size="icon" className="size-8">
                <a href={backup} download aria-label={t("backupDownload")}>
                  <Download className="size-4" />
                </a>
              </Button>
            </TooltipTrigger>
            <TooltipContent>{t("backupDownload")}</TooltipContent>
          </Tooltip>
        ) : null}
        {canStart ? (
          <Button size="sm" variant="outline" onClick={onStart} disabled={busy}>
            {busy ? <Loader2 className="size-4 animate-spin" /> : <Play className="size-4" />}
            {t("startAgain")}
          </Button>
        ) : null}
        {canManage && !IMPORT_IN_FLIGHT.includes(row.status) ? (
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                variant="ghost"
                size="icon"
                aria-label={t("remove")}
                className="size-8 text-destructive hover:bg-destructive/10 hover:text-destructive"
                onClick={onRemove}
                disabled={busy}
              >
                <Trash2 className="size-4" />
              </Button>
            </TooltipTrigger>
            <TooltipContent>{t("remove")}</TooltipContent>
          </Tooltip>
        ) : null}
      </div>
    </div>
  );
}
