import { useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { Download, Loader2 } from "lucide-react";
import { formatBytes } from "@/lib/format/bytes";
import { BACKUP_IN_FLIGHT, backupHasArchive } from "@/lib/schemas/backup";
import { fetchBackupDownload } from "@/lib/api/backups";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";

/**
 * Take the archive away.
 *
 * The counterpart to restore, and the reason someone keeps backups off-panel:
 * restore puts a copy back over the live site, this hands you the copy. It is
 * offered on failed runs too — a partial archive is sometimes what explains
 * the failure, and downloading overwrites nothing.
 *
 * The link is presigned and expires in five minutes, so it is fetched at the
 * moment of the click and navigated to immediately. It is never rendered into
 * the page: for those five minutes the URL *is* the credential.
 */
export function DownloadBackupButton({ backup, canDownload, label = false }) {
  const t = useTranslations("backups.download");
  const format = useFormatter();
  const [pending, setPending] = useState(false);

  // Only a verified run has an archive. A run still in flight has not written
  // one yet, and a failed run never will — the API answers 422
  // download_no_artifact for both, and meeting that after a click is worse
  // than a button that says why up front.
  //
  // This used to be offered on failed runs, on the theory that a partial
  // archive might explain the failure. The backend confirmed there is no
  // partial archive to hand back, so the button could only ever have produced
  // a 422. `reason_title` is the run's own explanation of why it failed, which
  // is a better thing to read here than anything this file could word.
  //
  // The check itself was then written as `status !== "completed"`, and there is
  // no "completed" backup — the success state is `verified`. So this blocked
  // every backup that had ever worked and told the reader it had failed, beside
  // a row whose own badge said Complete. Reported from exactly that screenshot.
  // Asking the shared predicate rather than a literal is what stops the two
  // screens drifting apart again.
  const blocker = !canDownload
    ? t("blocked.noPermission")
    : BACKUP_IN_FLIGHT.includes(backup.status)
      ? t("blocked.inFlight")
      : !backupHasArchive(backup.status)
        ? (backup.reason_title ?? t("blocked.noArtifact"))
        : null;

  async function download() {
    setPending(true);
    try {
      const response = await fetchBackupDownload(backup.id);
      const url = response.data?.download?.url;
      if (!url) throw new Error("missing");
      toast.success(t("started"));
      // Not fetch(): our interceptor's headers are not part of what the
      // signature covers, and the bucket is cross-origin.
      //
      // An anchor rather than `location.href`, though. A presigned link that
      // has expired, or whose object has been pruned, answers with an S3 error
      // page — and assigning `location.href` navigates the panel itself onto
      // it, so a failed download costs the user the screen they were on. A
      // detached anchor hands the URL to the browser's download machinery
      // instead; the panel stays put whatever the bucket says.
      const link = document.createElement("a");
      link.href = url;
      link.rel = "noopener";
      link.target = "_blank";
      link.download = "";
      document.body.appendChild(link);
      link.click();
      link.remove();
    } catch (error) {
      toast.error(apiMessage(error, t("failed")));
    } finally {
      setPending(false);
    }
  }

  const size = backup.size_bytes ? formatBytes(backup.size_bytes, format) : null;

  return (
    // Only the blocked state gets the wrapper: `ReasonTooltip` makes its span
    // focusable, which on an enabled button would add a second tab stop for
    // one control.
    <ReasonTooltip reason={blocker}>
      <Button
        variant="outline"
        size={label ? "sm" : "icon-sm"}
        disabled={Boolean(blocker) || pending}
        onClick={download}
        title={blocker ? undefined : size ? t("hintWithSize", { size }) : t("hint")}
        aria-label={label ? undefined : t("label")}
      >
        {pending ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />}
        {label ? t("label") : null}
      </Button>
    </ReasonTooltip>
  );
}
