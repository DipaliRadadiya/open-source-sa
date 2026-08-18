"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Image as ImageIcon, Loader2, Download } from "lucide-react";
import { fileDownloadUrl } from "@/lib/api/files";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

/**
 * Images get an actual preview instead of being routed through the text
 * editor, where the API's binary check would just reject them. `<img>`
 * ignores the download endpoint's `Content-Disposition: attachment` — that
 * header only affects a top-level navigation/download, not a subresource
 * fetch — so the same URL the Download button uses works here unchanged.
 */
export function ImagePreviewDialog({ appId, file, open, onOpenChange }) {
  const t = useTranslations("applications.files");
  // Mounted fresh per file (see files-panel.jsx), so this starts at "about to
  // load" directly — no effect needed to reset it between files.
  const [status, setStatus] = useState("loading"); // loading | loaded | error
  const src = fileDownloadUrl(appId, file.path);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <div className="flex min-w-0 items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
              <ImageIcon className="size-5" />
            </span>
            <DialogTitle className="truncate font-mono text-base">{file?.path}</DialogTitle>
          </div>
          <DialogDescription className="pt-1">{t("imagePreview.subtitle")}</DialogDescription>
        </DialogHeader>

        <div className="relative flex max-h-[60vh] items-center justify-center overflow-auto rounded-lg border bg-muted/30">
          {status === "loading" ? (
            <div className="flex h-56 items-center justify-center">
              <Loader2 className="size-5 animate-spin text-muted-foreground" />
            </div>
          ) : null}
          {status === "error" ? (
            <div className="flex h-56 flex-col items-center justify-center gap-3 px-6 text-center">
              <p className="max-w-sm text-sm text-muted-foreground">{t("imagePreview.loadFailed")}</p>
            </div>
          ) : (
            // next/image needs a known, whitelisted remote host and can't
            // forward the Sanctum cookie this authenticated URL needs —
            // a plain <img> is the right tool for an arbitrary site file.
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={src}
              alt={file?.name}
              onLoad={() => setStatus("loaded")}
              onError={() => setStatus("error")}
              className={status === "loaded" ? "max-h-[60vh] w-auto max-w-full object-contain" : "sr-only"}
            />
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange?.(false)}>
            {t("cancel")}
          </Button>
          <Button asChild>
            <a href={src} download={file?.name}>
              <Download className="size-4" />
              {t("actions.download")}
            </a>
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
