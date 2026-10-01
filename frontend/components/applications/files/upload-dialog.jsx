import { useRef, useState } from "react";
import { useTranslations, useFormatter } from "next-intl";
import { toast } from "sonner";
import { formatBytes } from "@/lib/format/bytes";
import { UploadCloud, X, Loader2, CircleCheck, CircleAlert, Square, Hourglass } from "lucide-react";
import { cn } from "@/lib/utils";
import { uploadAnySize, uploadSpace } from "@/lib/api/files";
import { joinPath } from "@/lib/files/path-helpers";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { IconTooltip } from "@/components/ui/icon-tooltip";
import { useRefresh } from "@/hooks/use-refresh";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

// Retry-After is not in the API's CORS exposed headers, so the browser hides it;
// wait in steps instead. The server's window is a minute, so four steps outlast it.
const RATE_LIMIT_WAIT_SECONDS = 20;
const RATE_LIMIT_MAX_WAITS = 4;

function retryAfterSeconds(error) {
  const header = Number(error?.response?.headers?.["retry-after"]);
  return Number.isFinite(header) && header > 0 && header <= 120 ? Math.ceil(header) : RATE_LIMIT_WAIT_SECONDS;
}

// The API takes one file per request and REFUSES an existing name
// (`upload_exists`); this sends multiple files sequentially with per-file
// progress and outcome.
export function UploadDialog({ appId, path, open, onOpenChange, initialFiles = null, existingNames = [], onSuccess }) {
  const t = useTranslations("applications.files");
  const format = useFormatter();
  const { pending: refreshing, refreshThen } = useRefresh();
  const [items, setItems] = useState([]); // { id, file, status, progress, error }
  const [dragOver, setDragOver] = useState(false);
  const [uploading, setUploading] = useState(false);
  const inputRef = useRef(null);
  // The run in progress, so Stop can cut it off mid-file. Both upload paths honour
  // the signal; the chunked one also deletes its half-written parts.
  const abortRef = useRef(null);
  // Which `initialFiles` reference has been folded into `items`, so each panel drop
  // (a fresh FileList) is seeded exactly once. Render-phase "adjust state on prop
  // change", not an effect, which would re-add files the user removed.
  const [seenInitialFiles, setSeenInitialFiles] = useState(null);

  // name+size+lastModified, not identity: the OS can hand over the same dropped
  // file twice (e.g. screenshots), and a re-drop should not double it.
  function fileKey(file) {
    return `${file.name}:${file.size}:${file.lastModified}`;
  }

  function addFiles(fileList) {
    // Copied out of the FileList *before* the state updater: `FileList` is live
    // (`<input>.value = ""` empties it, a drop's `dataTransfer` is neutered after the
    // event), and React may run the updater later. The first pick works because
    // React evaluates it eagerly; later picks would be silently empty.
    const picked = Array.from(fileList);
    if (!picked.length) return;

    const existing = new Set(existingNames);
    setItems((prev) => {
      const seen = new Set(prev.map((i) => fileKey(i.file)));
      const next = [];
      for (const file of picked) {
        const key = fileKey(file);
        if (seen.has(key)) continue;
        seen.add(key);
        /*
         * Flagged when picked: the server refuses a taken name. Only names in the listing
         * are known; a hidden file still meets the server's refusal, shown on the row.
         */
        const taken = existing.has(file.name);
        next.push({
          id: `${key}-${Math.random().toString(36).slice(2)}`,
          file,
          status: taken ? "error" : "pending",
          progress: 0,
          error: taken ? t("uploadDialog.exists") : null,
          spaceBlocked: false,
          nameTaken: taken,
        });
      }
      return [...prev, ...next];
    });

    checkSpace();
  }

  // Flags files the disk cannot take when picked, not partway through sending
  // (uploads are unbounded in size). Checked against the cumulative size of
  // everything queued.
  //
  // Advisory: the server re-checks every write, since other sites share the disk.
  // A failure here is ignored so it never blocks an upload that would fit.
  async function checkSpace() {
    let usable;
    try {
      ({ usable } = await uploadSpace(appId));
    } catch {
      return;
    }

    setItems((prev) => {
      let queued = 0;
      return prev.map((item) => {
        if (item.status === "done" || item.nameTaken) return item;

        // A file that does not fit is never sent, so it uses no room; counting it would
        // block smaller files after it.
        if (queued + item.file.size > usable) {
          return { ...item, status: "error", error: t("uploadDialog.noSpace"), spaceBlocked: true };
        }
        queued += item.file.size;
        // Room again: something ahead was removed or disk space was freed.
        return item.spaceBlocked
          ? { ...item, status: "pending", error: null, spaceBlocked: false }
          : item;
      });
    });
  }

  if (initialFiles && initialFiles !== seenInitialFiles) {
    setSeenInitialFiles(initialFiles);
    if (initialFiles.length) addFiles(initialFiles);
  }

  function removeItem(id) {
    setItems((prev) => prev.filter((i) => i.id !== id));
    // Removing one file can make room for the ones behind it.
    checkSpace();
  }

  function handleOpenChange(next) {
    if (uploading || refreshing) return;
    if (!next) {
      setItems([]);
      setDragOver(false);
    }
    onOpenChange?.(next);
  }

  // Resolves early when Stop is pressed, so a wait never outlives the run.
  function countDown(seconds, update, signal) {
    return new Promise((resolve) => {
      let left = seconds;
      update({ status: "waiting", progress: 0, waitSeconds: left });
      const timer = setInterval(() => {
        left -= 1;
        if (left <= 0) finish();
        else update({ waitSeconds: left });
      }, 1000);
      function finish() {
        clearInterval(timer);
        signal.removeEventListener("abort", finish);
        resolve();
      }
      signal.addEventListener("abort", finish);
    });
  }

  async function startUpload() {
    const controller = new AbortController();
    abortRef.current = controller;
    setUploading(true);
    let stopped = false;
    let anySucceeded = false;
    // Tracked separately: the `items` closure is the snapshot from when this run
    // started and cannot tell which files went through.
    const succeededNames = [];
    // Counted here for the same reason: the closure predates the run.
    let failedCount = 0;
    // A taken name is skipped deliberately, not counted as a failure.
    let skippedCount = 0;
    for (const item of items) {
      if (item.status === "done") {
        anySucceeded = true;
        succeededNames.push(item.file.name);
        continue;
      }
      // Taken names and files known not to fit are skipped; sending them would fail (or
      // fill the shared disk) only to be refused at the end.
      if (item.nameTaken) {
        skippedCount += 1;
        continue;
      }
      if (item.spaceBlocked) {
        failedCount += 1;
        continue;
      }
      const update = (patch) => setItems((prev) => prev.map((i) => (i.id === item.id ? { ...i, ...patch } : i)));
      let waits = 0;
      for (;;) {
        update({ status: "uploading", error: null, waitSeconds: 0 });
        try {
          await uploadAnySize(appId, joinPath(path, item.file.name), item.file, {
            signal: controller.signal,
            onProgress: (fraction) => update({ progress: fraction }),
          });
          anySucceeded = true;
          succeededNames.push(item.file.name);
          update({ status: "done", progress: 1 });
        } catch (error) {
          /*
           * The server allows a fixed number of uploads per minute. A refused request does
           * not count against the limit, so waiting and retrying loses nothing.
           */
          if (!controller.signal.aborted && error?.response?.status === 429 && waits < RATE_LIMIT_MAX_WAITS) {
            waits += 1;
            await countDown(retryAfterSeconds(error), update, controller.signal);
            if (!controller.signal.aborted) continue;
          }
          // Stopped by the user, not refused: the file goes back to waiting with no error,
          // so Upload can resume it.
          if (controller.signal.aborted) {
            stopped = true;
            update({ status: "pending", progress: 0, error: null, waitSeconds: 0 });
            break;
          }
          failedCount += 1;
          const message =
            error?.response?.status === 429
              ? t("uploadDialog.rateLimited")
              : apiMessage(error, t("uploadDialog.itemFailed"));
          update({ status: "error", error: message, progress: 0 });
        }
        break;
      }
      if (stopped) break;
    }
    abortRef.current = null;
    setUploading(false);
    const uploaded = succeededNames.length;
    const clean = !stopped && !failedCount && uploaded > 0 && !skippedCount;
    const report = () => {
      // Report the outcome explicitly; a row's tick is invisible once the list scrolls.
      if (stopped) {
        toast.info(t("uploadDialog.stopped", { done: uploaded, count: items.filter((i) => !i.nameTaken).length }));
        return;
      }

      if (!failedCount && uploaded && skippedCount) {
        // Stays open: the skipped rows say which files, and why.
        toast.success(t("uploadDialog.skipped", { done: uploaded, skipped: skippedCount }));
      } else if (!failedCount && uploaded) {
        toast.success(
          uploaded === 1
            ? t("uploadDialog.uploadedOne", { name: succeededNames[0] })
            : t("uploadDialog.uploadedMany", { count: uploaded }),
        );
      } else if (uploaded) {
        toast.warning(
          skippedCount
            ? t("uploadDialog.partialSkipped", { done: uploaded, skipped: skippedCount, failed: failedCount })
            : t("uploadDialog.partial", { done: uploaded, failed: failedCount }),
        );
      } else if (failedCount) {
        toast.error(t("uploadDialog.allFailed"));
      }
    };

    if (anySucceeded) {
      // Close after the list has re-read, so the new file is already shown.
      refreshThen(() => {
        // Reported once the list shows the files.
        report();
        // Only unambiguous with exactly one file.
        if (succeededNames.length === 1) onSuccess?.(joinPath(path, succeededNames[0]));
        // Only on a clean run: per-file failure reasons are only shown here.
        if (clean) handleOpenChange(false);
      });
    } else {
      report();
    }
  }

  // A file known not to fit is skipped like a taken name; counting it as pending
  // would leave Upload enabled with nothing to send.
  const hasPending = items.some(
    (i) => !i.nameTaken && !i.spaceBlocked && (i.status === "pending" || i.status === "error"),
  );

  // Batch progress weighted by bytes, not file count, so one large file does not
  // stall the bar at 80%. A finished file counts whole so the total never goes
  // backwards. Name-refused files are never sent and excluded.
  const totalBytes = items.reduce((sum, i) => (i.nameTaken ? sum : sum + i.file.size), 0);
  const sentBytes = items.reduce(
    // A failed file adds nothing, or a run with failures would still reach 100%.
    (sum, i) =>
      sum + (i.status === "done" ? i.file.size : i.status === "error" ? 0 : i.file.size * (i.progress || 0)),
    0,
  );
  const doneCount = items.filter((i) => i.status === "done").length;
  // Same set as the byte total: name-refused files are never sent.
  const sendable = items.filter((i) => !i.nameTaken).length;
  const overallPercent = totalBytes ? Math.round((sentBytes / totalBytes) * 100) : 0;

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <div className="flex items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
              <UploadCloud className="size-5" />
            </span>
            <DialogTitle>{t("uploadDialog.title")}</DialogTitle>
          </div>
          <DialogDescription className="pt-1">{t("uploadDialog.subtitle")}</DialogDescription>
        </DialogHeader>

        <div
          onDragOver={(e) => {
            e.preventDefault();
            setDragOver(true);
          }}
          onDragLeave={() => setDragOver(false)}
          onDrop={(e) => {
            e.preventDefault();
            setDragOver(false);
            if (e.dataTransfer.files?.length) addFiles(e.dataTransfer.files);
          }}
          onClick={() => inputRef.current?.click()}
          role="button"
          tabIndex={0}
          className={cn(
            "flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed p-6 text-center transition-colors",
            dragOver ? "border-primary bg-primary/5" : "border-border hover:bg-muted/40",
          )}
        >
          <UploadCloud className="size-6 text-muted-foreground" />
          <p className="text-sm text-muted-foreground">{t("uploadDialog.dropHint")}</p>
          <input
            ref={inputRef}
            type="file"
            multiple
            className="sr-only"
            onChange={(e) => {
              if (e.target.files?.length) addFiles(e.target.files);
              e.target.value = "";
            }}
          />
        </div>

        {items.length > 1 && (uploading || doneCount) ? (
          <div className="space-y-1.5 rounded-lg border bg-muted/30 px-3 py-2">
            <div className="flex items-center justify-between gap-2 text-xs">
              <span className="text-muted-foreground">
                {t("uploadDialog.overall", { done: doneCount, count: sendable })}
              </span>
              <span className="shrink-0 font-medium tabular-nums">{overallPercent}%</span>
            </div>
            <div className="h-1.5 overflow-hidden rounded-full bg-muted">
              <div
                className="h-full rounded-full bg-primary transition-all"
                style={{ width: `${overallPercent}%` }}
              />
            </div>
            <p className="text-xs text-muted-foreground tabular-nums">
              {formatBytes(sentBytes, format)} / {formatBytes(totalBytes, format)}
            </p>
          </div>
        ) : null}

        {items.length ? (
          <ul className="max-h-56 space-y-1.5 overflow-y-auto">
            {items.map((item) => (
              <li key={item.id} className="rounded-lg border px-3 py-2">
                <div className="flex items-center gap-2">
                  <span className="min-w-0 flex-1 truncate font-mono text-xs">{item.file.name}</span>
                  {item.status === "uploading" ? (
                    <Loader2 className="size-4 shrink-0 animate-spin text-muted-foreground" />
                  ) : item.status === "waiting" ? (
                    <Hourglass className="size-4 shrink-0 text-muted-foreground" />
                  ) : item.status === "done" ? (
                    <CircleCheck className="size-4 shrink-0 text-success" />
                  ) : item.status === "error" ? (
                    <CircleAlert className="size-4 shrink-0 text-destructive" />
                  ) : null}
                  {item.status === "pending" || item.status === "error" ? (
                    <IconTooltip label={t("uploadDialog.remove")}>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-6 shrink-0"
                        onClick={() => removeItem(item.id)}
                        aria-label={t("uploadDialog.remove")}
                      >
                        <X className="size-3.5" />
                      </Button>
                    </IconTooltip>
                  ) : null}
                </div>
                {item.status === "uploading" ? (
                  <>
                    <div className="mt-1.5 h-1 overflow-hidden rounded-full bg-muted">
                      <div
                        className="h-full rounded-full bg-primary transition-all"
                        style={{ width: `${Math.round(item.progress * 100)}%` }}
                      />
                    </div>
                    {/* Bytes as well as percent, so a large chunked upload does not look stalled. */}
                    <div className="mt-1 flex items-center justify-between gap-2 text-xs text-muted-foreground tabular-nums">
                      <span>
                        {formatBytes(item.file.size * (item.progress || 0), format)} /{" "}
                        {formatBytes(item.file.size, format)}
                      </span>
                      <span>{Math.round(item.progress * 100)}%</span>
                    </div>
                  </>
                ) : (
                  <p className="mt-1 text-xs text-muted-foreground tabular-nums">
                    {formatBytes(item.file.size, format)}
                  </p>
                )}
                {item.status === "waiting" ? (
                  <p className="mt-1 text-xs text-muted-foreground">
                    {t("uploadDialog.waiting", { seconds: item.waitSeconds })}
                  </p>
                ) : null}
                {item.error ? <p className="mt-1 text-xs text-destructive">{item.error}</p> : null}
              </li>
            ))}
          </ul>
        ) : null}

        <DialogFooter>
          {uploading ? (
            <Button type="button" variant="outline" onClick={() => abortRef.current?.abort()}>
              <Square className="size-3.5" />
              {t("uploadDialog.stop")}
            </Button>
          ) : (
            <Button type="button" variant="outline" onClick={() => handleOpenChange(false)} disabled={refreshing}>
              {t("cancel")}
            </Button>
          )}
          <Button type="button" onClick={startUpload} disabled={!hasPending || uploading || refreshing}>
            {uploading || refreshing ? <Loader2 className="size-4 animate-spin" /> : null}
            {t("uploadDialog.submit")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
