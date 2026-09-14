"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Image as ImageIcon, Loader2, Download } from "lucide-react";
import { fetchFilePreview, fileDownloadUrl } from "@/lib/api/files";
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
 * Show an image that lives in the site.
 *
 * This pointed at the download endpoint, which cannot work: that response is
 * `application/octet-stream` with `nosniff`, so the browser is explicitly told
 * not to treat it as an image. It is what "images not loading" was.
 *
 * The preview endpoint decides previewability from the file's own BYTES, not
 * its name, so the answer can disagree with the extension in both directions —
 * a .png that is really text is refused, and the refusal is the truth. Nothing
 * here second-guesses it.
 *
 * Two of the three refusals have a way out, and Download is it: an SVG is
 * refused deliberately (it can carry script, and this would be served inline
 * from the API origin), and a file over the size limit is still perfectly
 * downloadable. Only "not an image" leaves nothing to offer.
 */
export function ImagePreviewDialog({ appId, file, open, onOpenChange }) {
  const t = useTranslations("applications.files");
  const [state, setState] = useState({ status: "loading" });

  /*
   * Back to "loading" when the file changes, synced during render rather than
   * in the effect below. The panel mounts this fresh per file so today it only
   * ever runs once — but resetting inside the effect is the cascading render
   * the lint rule refuses, and an effect would paint the previous image for a
   * frame first. Same pattern as the search box.
   */
  const [seenPath, setSeenPath] = useState(file?.path);
  if (seenPath !== file?.path) {
    setSeenPath(file?.path);
    setState({ status: "loading" });
  }

  useEffect(() => {
    if (!file?.path) return undefined;
    const controller = new AbortController();
    let objectUrl = null;

    fetchFilePreview(appId, file.path, { signal: controller.signal })
      .then((result) => {
        if (controller.signal.aborted) {
          // Resolved after the dialog closed: nothing will revoke it later, so
          // it has to go now or it leaks for the life of the document.
          if (result.url) URL.revokeObjectURL(result.url);
          return;
        }
        if (result.url) {
          objectUrl = result.url;
          setState({ status: "loaded", url: result.url });
        } else {
          setState({ status: "error", message: result.error?.message ?? null });
        }
      })
      .catch((err) => {
        if (err?.name === "AbortError") return;
        setState({ status: "error", message: null });
      });

    return () => {
      controller.abort();
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [appId, file?.path]);

  const downloadHref = file?.path ? fileDownloadUrl(appId, file.path) : null;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <div className="flex min-w-0 items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
              <ImageIcon className="size-5" />
            </span>
            <DialogTitle className="truncate font-mono text-base" title={file?.path}>
              {file?.path}
            </DialogTitle>
          </div>
          <DialogDescription className="pt-1">{t("imagePreview.subtitle")}</DialogDescription>
        </DialogHeader>

        <div className="relative flex max-h-[60vh] items-center justify-center overflow-auto rounded-lg border bg-muted/30">
          {state.status === "loading" ? (
            <div className="flex h-56 items-center justify-center">
              <Loader2 className="size-5 animate-spin text-muted-foreground" aria-hidden />
              <span className="sr-only">{t("imagePreview.loading")}</span>
            </div>
          ) : null}

          {state.status === "error" ? (
            <div className="flex h-56 flex-col items-center justify-center gap-2 px-6 text-center">
              {/* The API's sentence says which of the three it is and, for an
                  SVG, why it is refused rather than broken. Our own copy is
                  only for a failure that carried no message at all. */}
              <p className="max-w-sm text-sm text-muted-foreground">
                {state.message ?? t("imagePreview.loadFailed")}
              </p>
            </div>
          ) : null}

          {state.status === "loaded" ? (
            // A blob URL, so next/image has nothing to optimise and no remote
            // host to whitelist — a plain <img> is the right tool.
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={state.url}
              alt={file?.name ?? ""}
              className="max-h-[60vh] w-auto max-w-full object-contain"
            />
          ) : null}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange?.(false)}>
            {t("cancel")}
          </Button>
          {downloadHref ? (
            <Button asChild>
              <a href={downloadHref} download={file?.name}>
                <Download className="size-4" />
                {t("actions.download")}
              </a>
            </Button>
          ) : null}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
