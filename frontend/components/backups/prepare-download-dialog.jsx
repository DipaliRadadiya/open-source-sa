import { useCallback, useEffect, useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import { CircleAlert, CircleCheck, Download, HardDriveDownload, Loader2, RotateCw } from "lucide-react";
import { fetchBackupDownloadPreparation, prepareBackupDownload } from "@/lib/api/backups";
import { apiMessage } from "@/lib/api/error-message";
import { formatBytes } from "@/lib/format/bytes";
import { parseApiDate } from "@/lib/format/api-date";
import { Button } from "@/components/ui/button";
import { FormModal } from "@/components/ui/form-modal";
import { Progress } from "@/components/ui/progress";

const POLL_MS = 3000;

// SFTP/FTP backups: the panel copies the archive to its own disk, then serves it through a
// signed link for an hour. Closing this dialog does not stop the copy; the button POSTs
// again on the next click, which answers with the copy already under way.
// `initial`: that first POST's outcome, `{prep}` or `{refusal}`. Mounted only while open.
export function PrepareDownloadDialog({ backup, initial, open, onOpenChange }) {
  const t = useTranslations("backups.download.prepare");
  const format = useFormatter();
  const [prep, setPrep] = useState(initial?.prep ?? null);
  // Refusals before any copy exists (no disk space, no permission): the API's sentence.
  const [refusal, setRefusal] = useState(initial?.refusal ?? null);
  const [starting, setStarting] = useState(false);
  const [opening, setOpening] = useState(false);
  const [busyFor, setBusyFor] = useState(null);

  const start = useCallback(async () => {
    setStarting(true);
    setRefusal(null);
    setBusyFor(null);
    try {
      const { data } = await prepareBackupDownload(backup.id);
      setPrep(data?.download_preparation ?? null);
    } catch (error) {
      setPrep(null);
      setRefusal(apiMessage(error, t("startFailed")));
    } finally {
      setStarting(false);
    }
  }, [backup.id, t]);

  const preparing = prep?.status === "preparing";
  useEffect(() => {
    if (!open || !preparing) return undefined;
    const controller = new AbortController();
    const id = setInterval(async () => {
      try {
        const { data } = await fetchBackupDownloadPreparation(backup.id, { signal: controller.signal });
        if (data?.download_preparation) setPrep(data.download_preparation);
      } catch {
        // The next tick asks again; a failed copy is reported in the status itself.
      }
    }, POLL_MS);
    return () => {
      clearInterval(id);
      controller.abort();
    };
  }, [open, preparing, backup.id]);

  // A 1-byte look first, so "busy" or "expired" is said here instead of as JSON in a new
  // tab. bytes=1-1, not 0-0: a range from byte 0 is logged as a download.
  async function fetchFile() {
    setOpening(true);
    setBusyFor(null);
    try {
      const probe = await fetch(prep.url, {
        credentials: "include",
        headers: { Range: "bytes=1-1" },
        cache: "no-store",
      });
      if (probe.status === 503) {
        setBusyFor(Number(probe.headers.get("Retry-After")) || 30);
        return;
      }
      if (probe.status === 410) {
        await start();
        return;
      }
      if (!probe.ok) {
        setRefusal(t("fileFailed"));
        return;
      }
      const link = document.createElement("a");
      link.href = prep.url;
      link.rel = "noopener";
      link.download = prep.filename ?? "";
      document.body.appendChild(link);
      link.click();
      link.remove();
    } catch {
      setRefusal(t("fileFailed"));
    } finally {
      setOpening(false);
    }
  }

  const status = refusal ? "refused" : (prep?.status ?? "starting");
  const total = prep?.bytes_total ?? backup.size_bytes ?? null;
  const done = prep?.bytes_transferred ?? null;
  const percent = total && done != null ? Math.min(100, Math.round((done / total) * 100)) : null;
  const expires = parseApiDate(prep?.expires_at);

  const icon =
    status === "ready" ? CircleCheck : status === "failed" || status === "refused" ? CircleAlert : HardDriveDownload;

  const title =
    status === "ready"
      ? t("readyTitle")
      : status === "failed" || status === "refused"
        ? t("failedTitle")
        : status === "expired"
          ? t("expiredTitle")
          : t("preparingTitle");

  const retry = (
    <Button onClick={start} disabled={starting}>
      {starting ? <Loader2 className="size-4 animate-spin" /> : <RotateCw className="size-4" />}
      {status === "expired" ? t("prepareAgain") : t("tryAgain")}
    </Button>
  );

  return (
    <FormModal
      open={open}
      onOpenChange={onOpenChange}
      icon={icon}
      iconTone={status === "ready" ? "success" : status === "failed" || status === "refused" ? "destructive" : "primary"}
      title={title}
      description={prep?.filename ?? backup.filename ?? null}
      initialFocus="[data-download-action]"
      footer={
        <>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t("close")}
          </Button>
          {status === "ready" ? (
            <Button onClick={fetchFile} disabled={opening} data-download-action>
              {opening ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />}
              {t("download")}
            </Button>
          ) : status === "failed" || status === "expired" || status === "refused" ? (
            retry
          ) : null}
        </>
      }
    >
      {status === "starting" || status === "preparing" ? (
        <div className="space-y-3" aria-live="polite">
          <p className="text-sm">{t("preparingBody")}</p>
          <Progress value={percent ?? 0} indeterminate={percent == null} aria-label={t("preparingTitle")} />
          <p className="text-xs tabular-nums text-muted-foreground">
            {done != null && total
              ? t("progress", { done: formatBytes(done, format), total: formatBytes(total, format) })
              : t("connecting")}
          </p>
          <p className="text-xs text-muted-foreground">{t("closeHint")}</p>
        </div>
      ) : null}

      {status === "ready" ? (
        <div className="space-y-2" aria-live="polite">
          <p className="text-sm">{t("readyBody")}</p>
          {expires ? (
            <p className="text-xs text-muted-foreground">
              {t("availableUntil", { time: format.dateTime(expires, { timeStyle: "short" }) })}
            </p>
          ) : null}
          {busyFor ? (
            <p role="alert" className="rounded-lg border border-warning/30 bg-warning/5 px-3 py-2 text-sm">
              {t("busy", { seconds: busyFor })}
            </p>
          ) : null}
        </div>
      ) : null}

      {status === "failed" ? (
        <div role="alert" className="space-y-1 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm">
          <p>{prep?.message || t("failedBody")}</p>
          {prep?.reference ? <p className="font-mono text-xs text-muted-foreground">{prep.reference}</p> : null}
        </div>
      ) : null}

      {status === "expired" ? <p className="text-sm">{prep?.message || t("expiredBody")}</p> : null}

      {status === "refused" ? (
        <p role="alert" className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm">
          {refusal}
        </p>
      ) : null}
    </FormModal>
  );
}
