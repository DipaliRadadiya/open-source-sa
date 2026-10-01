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
 * Download the archive. The presigned link expires in five minutes, so it is
 * fetched on click and opened immediately; never rendered into the page, as
 * the URL is the credential.
 */
export function DownloadBackupButton({ backup, canDownload, label = false }) {
  const t = useTranslations("backups.download");
  const format = useFormatter();
  const [pending, setPending] = useState(false);

  // Only a verified run has an archive; in-flight and failed runs get a 422
  // download_no_artifact (there is no partial archive). The success state is
  // `verified`, not "completed", so use the shared predicate, not a literal.
  // `reason_title` is the run's own failure explanation.
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
      // Not fetch(): our interceptor's headers are not covered by the signature,
      // and the bucket is cross-origin. A detached anchor, not `location.href`:
      // an expired link returns an S3 error page that would replace the panel.
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
    // focusable, which would add a second tab stop to an enabled button.
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
