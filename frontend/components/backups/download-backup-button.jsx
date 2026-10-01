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

// The presigned link expires in five minutes, so fetch on click; never render it, as
// the URL is the credential.
export function DownloadBackupButton({ backup, canDownload, label = false }) {
  const t = useTranslations("backups.download");
  const format = useFormatter();
  const [pending, setPending] = useState(false);

  // Only a verified run has an archive (others get 422 download_no_artifact). Use the
  // shared predicate: the success state is `verified`, not "completed".
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
      // Not fetch(): our headers break the signature. Not `location.href`: an expired
      // link's S3 error page would replace the panel.
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
