"use client";

import { useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations, useFormatter } from "next-intl";
import { toast } from "sonner";
import { formatBytes } from "@/lib/format/bytes";
import { UploadCloud, X, Loader2, CircleCheck, CircleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { uploadAnySize, uploadSpace } from "@/lib/api/files";
import { joinPath } from "@/lib/files/path-helpers";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

// The API takes one file per request and overwrites whatever's already at the
// target path — this orchestrates multiple drops sequentially against that
// single-file endpoint, with its own progress/success/fail per file, so a
// five-file drop doesn't read as "it uploaded one file and silently ignored
// the rest."
export function UploadDialog({ appId, path, open, onOpenChange, initialFiles = null, onSuccess }) {
  const t = useTranslations("applications.files");
  const format = useFormatter();
  const router = useRouter();
  const [items, setItems] = useState([]); // { id, file, status, progress, error }
  const [dragOver, setDragOver] = useState(false);
  const [uploading, setUploading] = useState(false);
  const inputRef = useRef(null);
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
    setItems((prev) => {
      const seen = new Set(prev.map((i) => fileKey(i.file)));
      const next = [];
      for (const file of fileList) {
        const key = fileKey(file);
        if (seen.has(key)) continue;
        seen.add(key);
        next.push({
          id: `${key}-${Math.random().toString(36).slice(2)}`,
          file,
          status: "pending",
          progress: 0,
          error: null,
          spaceBlocked: false,
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
        if (item.status === "done") return item;
        queued += item.file.size;

        if (queued > usable) {
          return { ...item, status: "error", error: t("uploadDialog.noSpace"), spaceBlocked: true };
        }
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
    if (uploading) return;
    if (!next) {
      setItems([]);
      setDragOver(false);
    }
    onOpenChange?.(next);
  }

  async function startUpload() {
    setUploading(true);
    let anySucceeded = false;
    // Tracked separately from `items` state — the closure over `items` here
    // stays the array from when this run started, never the `setItems`
    // updates made along the way, so it can't be used to tell afterward which
    // files actually went through.
    const succeededNames = [];
    // Counted here rather than read back off `items` afterwards, for the same
    // reason: the closure is the snapshot from before the run.
    let failedCount = 0;
    for (const item of items) {
      if (item.status === "done") {
        anySucceeded = true;
        succeededNames.push(item.file.name);
        continue;
      }
      // Known not to fit. Sending it anyway would fill the disk that every
      // hosted site shares, only to be refused at the last chunk.
      if (item.spaceBlocked) {
        failedCount += 1;
        continue;
      }
      setItems((prev) => prev.map((i) => (i.id === item.id ? { ...i, status: "uploading", error: null } : i)));
      try {
        await uploadAnySize(appId, joinPath(path, item.file.name), item.file, {
          onProgress: (fraction) =>
            setItems((prev) => prev.map((i) => (i.id === item.id ? { ...i, progress: fraction } : i))),
        });
        anySucceeded = true;
        succeededNames.push(item.file.name);
        setItems((prev) => prev.map((i) => (i.id === item.id ? { ...i, status: "done", progress: 1 } : i)));
      } catch (error) {
        failedCount += 1;
        const message = apiMessage(error, t("uploadDialog.itemFailed"));
        setItems((prev) => prev.map((i) => (i.id === item.id ? { ...i, status: "error", error: message } : i)));
      }
    }
    setUploading(false);
    if (anySucceeded) {
      router.refresh();
      // Only unambiguous with exactly one file — a multi-file batch has no
      // single row that "the" upload landed at.
      if (succeededNames.length === 1) onSuccess?.(joinPath(path, succeededNames[0]));
    }

    // Say what happened. Previously nothing did: the only completion signal
    // was a row's spinner turning into a tick, which is invisible if the list
    // has scrolled, and the auto-close never fired at all — it tested the
    // `items` closure captured before the run, where every item is still
    // "pending", so the "everything finished" condition could never be true.
    const uploaded = succeededNames.length;

    if (!failedCount && uploaded) {
      toast.success(
        uploaded === 1
          ? t("uploadDialog.uploadedOne", { name: succeededNames[0] })
          : t("uploadDialog.uploadedMany", { count: uploaded }),
      );
      // Long enough for the last row to be seen ticking over, short enough
      // not to feel stuck. Only on a clean run — a partial failure has to
      // stay on screen, since the per-file reason is only shown here.
      setTimeout(() => handleOpenChange(false), 700);
    } else if (uploaded) {
      toast.warning(t("uploadDialog.partial", { done: uploaded, failed: failedCount }));
    } else if (failedCount) {
      toast.error(t("uploadDialog.allFailed"));
    }
  }

  const hasPending = items.some((i) => i.status === "pending" || i.status === "error");

  // Batch progress, weighted by bytes rather than by file count: with a 2 GB
  // file next to four 10 KB ones, "4 of 5 done" would sit at 80% for almost
  // the entire upload and then crawl. A finished file counts whole, so the
  // total never goes backwards when one completes.
  const totalBytes = items.reduce((sum, i) => sum + i.file.size, 0);
  const sentBytes = items.reduce(
    (sum, i) => sum + (i.status === "done" ? i.file.size : i.file.size * (i.progress || 0)),
    0,
  );
  const doneCount = items.filter((i) => i.status === "done").length;
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
                {t("uploadDialog.overall", { done: doneCount, count: items.length })}
              </span>
              <span className="shrink-0 font-medium tabular-nums">{overallPercent}%</span>
            </div>
            <div className="h-1.5 overflow-hidden rounded-full bg-muted">
              <div
                className="h-full rounded-full bg-primary transition-all"
                style={{ width: `${overallPercent}%` }}
              />
            </div>
            <p className="text-[11px] text-muted-foreground tabular-nums">
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
                  ) : item.status === "done" ? (
                    <CircleCheck className="size-4 shrink-0 text-success" />
                  ) : item.status === "error" ? (
                    <CircleAlert className="size-4 shrink-0 text-destructive" />
                  ) : null}
                  {item.status === "pending" || item.status === "error" ? (
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
                    <div className="mt-1 flex items-center justify-between gap-2 text-[11px] text-muted-foreground tabular-nums">
                      <span>
                        {formatBytes(item.file.size * (item.progress || 0), format)} /{" "}
                        {formatBytes(item.file.size, format)}
                      </span>
                      <span>{Math.round(item.progress * 100)}%</span>
                    </div>
                  </>
                ) : (
                  <p className="mt-1 text-[11px] text-muted-foreground tabular-nums">
                    {formatBytes(item.file.size, format)}
                  </p>
                )}
                {item.error ? <p className="mt-1 text-xs text-destructive">{item.error}</p> : null}
              </li>
            ))}
          </ul>
        ) : null}

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => handleOpenChange(false)} disabled={uploading}>
            {t("cancel")}
          </Button>
          <Button type="button" onClick={startUpload} disabled={!hasPending || uploading}>
            {uploading ? <Loader2 className="size-4 animate-spin" /> : null}
            {t("uploadDialog.submit")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
