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

// Retry-After is not in the API's CORS exposed headers, so the browser hides
// it; without it, wait in steps. The server's window is a minute, so four
// steps always outlast it.
const RATE_LIMIT_WAIT_SECONDS = 20;
const RATE_LIMIT_MAX_WAITS = 4;

function retryAfterSeconds(error) {
  const header = Number(error?.response?.headers?.["retry-after"]);
  return Number.isFinite(header) && header > 0 && header <= 120 ? Math.ceil(header) : RATE_LIMIT_WAIT_SECONDS;
}

// The API takes one file per request and REFUSES a name that already exists
// in the folder (`upload_exists`) — this orchestrates multiple drops sequentially against that
// single-file endpoint, with its own progress/success/fail per file, so a
// five-file drop doesn't read as "it uploaded one file and silently ignored
// the rest."
export function UploadDialog({ appId, path, open, onOpenChange, initialFiles = null, existingNames = [], onSuccess }) {
  const t = useTranslations("applications.files");
  const format = useFormatter();
  const { pending: refreshing, refreshThen } = useRefresh();
  const [items, setItems] = useState([]); // { id, file, status, progress, error }
  const [dragOver, setDragOver] = useState(false);
  const [uploading, setUploading] = useState(false);
  const inputRef = useRef(null);
  // The run in progress, so Stop can cut it off mid-file. Both upload paths
  // honour the signal; the chunked one also deletes its half-written parts.
  const abortRef = useRef(null);
  // Tracks which `initialFiles` reference has already been folded into
  // `items`, so a drop on the panel (a fresh FileList each time, even while
  // this dialog is already open) is seeded exactly once — a render-phase
  // update per the "adjusting state when a prop changes" pattern, not an
  // effect, since seeding on every commit rather than the actual change would
  // re-add the same files after they'd already been removed from the list.
  const [seenInitialFiles, setSeenInitialFiles] = useState(null);

  // name+size+lastModified, not object identity — a dropped screenshot is a
  // known case where the OS hands the browser the same file twice in one
  // `dataTransfer.files` (multiple pasteboard representations resolving to
  // two File objects), and a user re-dropping the same file they already
  // queued shouldn't double it either.
  function fileKey(file) {
    return `${file.name}:${file.size}:${file.lastModified}`;
  }

  function addFiles(fileList) {
    // Copied out of the FileList *before* the state updater, not inside it.
    // `FileList` is live: `<input>.value = ""` empties it, and a dropped
    // `dataTransfer` is neutered when the event ends. React runs an updater
    // during the next render — after both of those have happened — so reading
    // the list in there iterated nothing and the picked files vanished with no
    // error. It survived review because the very first pick works: with no
    // update queued React evaluates the updater eagerly, inside the call,
    // while the list still has contents. Every pick after that was silently
    // dropped.
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
         * Said when the file is picked, not after it has been sent: the server
         * refuses a name that is already taken here, and the dialog used to
         * promise it would overwrite instead. Only catches names in the
         * listing — a hidden file the reader has hidden still meets the
         * server's own refusal, which is shown on the row the same way.
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

  // Flags files the disk cannot take, at the moment they are picked rather
  // than partway through sending them — uploads here are unbounded in size,
  // so "we ran out of room" can otherwise arrive an hour in.
  //
  // Checked against the *cumulative* size of everything still queued, not
  // each file alone: five 3 GB files fit individually and not together.
  //
  // Advisory. The server re-checks on every write, because this number is
  // stale the moment it arrives — every other site on the box shares the
  // disk and is writing to it too. A failure here is therefore ignored: it
  // must never be the reason an upload the disk could take is refused.
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

        // A file that does not fit is never sent, so it uses none of the room:
        // counting it anyway blocked every smaller file listed after it.
        if (queued + item.file.size > usable) {
          return { ...item, status: "error", error: t("uploadDialog.noSpace"), spaceBlocked: true };
        }
        queued += item.file.size;
        // Room again — because something ahead of it was removed, or the
        // disk was freed up elsewhere.
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
    // Dropping one file can make room for the ones behind it.
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
    // Tracked separately from `items` state — the closure over `items` here
    // stays the array from when this run started, never the `setItems`
    // updates made along the way, so it can't be used to tell afterward which
    // files actually went through.
    const succeededNames = [];
    // Counted here rather than read back off `items` afterwards, for the same
    // reason: the closure is the snapshot from before the run.
    let failedCount = 0;
    // A taken name is skipped on purpose, not a failure — the toast said
    // "1 failed" for a file the dialog had already said it would not send.
    let skippedCount = 0;
    for (const item of items) {
      if (item.status === "done") {
        anySucceeded = true;
        succeededNames.push(item.file.name);
        continue;
      }
      // Known not to fit. Sending it anyway would fill the disk that every
      // hosted site shares, only to be refused at the last chunk.
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
           * The server takes a fixed number of uploads a minute, so a folder
           * of twelve files used to end with four rows reading "Too Many
           * Attempts." Waiting is the whole fix — a refused request does not
           * count against the limit, so trying again after the window loses
           * nothing.
           */
          if (!controller.signal.aborted && error?.response?.status === 429 && waits < RATE_LIMIT_MAX_WAITS) {
            waits += 1;
            await countDown(retryAfterSeconds(error), update, controller.signal);
            if (!controller.signal.aborted) continue;
          }
          // Stopped by the reader, not refused by the server: the file goes
          // back to waiting, with no error on it, so Upload can pick it up again.
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
      // Say what happened. Previously nothing did: the only completion signal
      // was a row's spinner turning into a tick, which is invisible if the list
      // has scrolled, and the auto-close never fired at all — it tested the
      // `items` closure captured before the run, where every item is still
      // "pending", so the "everything finished" condition could never be true.
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
      // After the list has re-read, not before: closing first showed a list
      // without the new file for seconds on a real server.
      refreshThen(() => {
        // Said once the list shows the files: the toast used to land ~1.5 s
        // before the rows did.
        report();
        // Only unambiguous with exactly one file — a multi-file batch has no
        // single row that "the" upload landed at.
        if (succeededNames.length === 1) onSuccess?.(joinPath(path, succeededNames[0]));
        // Only on a clean run — a partial failure has to stay on screen,
        // since the per-file reason is only shown here.
        if (clean) handleOpenChange(false);
      });
    } else {
      report();
    }
  }

  // A file known not to fit is skipped like a taken name, so it is not "to do":
  // counting it left Upload enabled with nothing it could send.
  const hasPending = items.some(
    (i) => !i.nameTaken && !i.spaceBlocked && (i.status === "pending" || i.status === "error"),
  );

  // Batch progress, weighted by bytes rather than by file count: with a 2 GB
  // file next to four 10 KB ones, "4 of 5 done" would sit at 80% for almost
  // the entire upload and then crawl. A finished file counts whole, so the
  // total never goes backwards when one completes.
  // A file refused for its name is never sent, so it is not part of "how far".
  const totalBytes = items.reduce((sum, i) => (i.nameTaken ? sum : sum + i.file.size), 0);
  const sentBytes = items.reduce(
    // A failed file sent nothing that stayed, so it adds nothing — otherwise
    // a run where four files were refused still ended at 100%.
    (sum, i) =>
      sum + (i.status === "done" ? i.file.size : i.status === "error" ? 0 : i.file.size * (i.progress || 0)),
    0,
  );
  const doneCount = items.filter((i) => i.status === "done").length;
  // Same set the byte total uses: a file refused for its name is never sent.
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
                    {/* How far along, in bytes as well as percent: on a file
                        large enough to need chunking, a bar that has barely
                        moved for a minute is indistinguishable from a stall
                        unless the number behind it is visible. */}
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
