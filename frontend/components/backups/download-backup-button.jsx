import { useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { Download, Loader2 } from "lucide-react";
import { formatBytes } from "@/lib/format/bytes";
import { BACKUP_IN_FLIGHT, backupHasArchive } from "@/lib/schemas/backup";
import { fetchBackupDownload, prepareBackupDownload } from "@/lib/api/backups";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { DENIED, ReasonTooltip } from "@/components/ui/reason-tooltip";
import { PrepareDownloadDialog } from "@/components/backups/prepare-download-dialog";
import { HoverHint } from "@/components/ui/hover-hint";

// The presigned link expires in 5 minutes and is itself the credential, so fetch on click.
export function DownloadBackupButton({ backup, canDownload, label = false }) {
  const t = useTranslations("backups.download");
  const format = useFormatter();
  const [pending, setPending] = useState(false);
  const [preparing, setPreparing] = useState(null);

  const blocker = !canDownload
    ? DENIED
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
      const link = document.createElement("a");
      link.href = new URL(url, process.env.NEXT_PUBLIC_API_URL).href;
      link.rel = "noopener";
      link.target = "_blank";
      link.download = "";
      document.body.appendChild(link);
      link.click();
      link.remove();
    } catch (error) {
      if (error?.response?.status === 409 && error.response.data?.reason === "prepare_required") {
        try {
          const { data } = await prepareBackupDownload(backup.id);
          setPreparing(
            data?.download_preparation
              ? { prep: data.download_preparation }
              : { refusal: t("prepare.startFailed") },
          );
        } catch (prepareError) {
          setPreparing({ refusal: apiMessage(prepareError, t("prepare.startFailed")) });
        }
        return;
      }
      toast.error(apiMessage(error, t("failed")));
    } finally {
      setPending(false);
    }
  }

  const size = backup.size_bytes ? formatBytes(backup.size_bytes, format) : null;

  return (
    <>
    <ReasonTooltip reason={blocker}>
      <HoverHint content={blocker ? undefined : size ? t("hintWithSize", { size }) : t("hint")}><Button
        variant={label ? "outline" : "ghost"}
        size={label ? "sm" : "icon-sm"}
        disabled={Boolean(blocker) || pending}
        onClick={download}
        aria-label={label ? undefined : t("label")}
      >
        {pending ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />}
        {label ? t("label") : null}
      </Button></HoverHint>
    </ReasonTooltip>
    {preparing ? (
      <PrepareDownloadDialog
        backup={backup}
        initial={preparing}
        open
        onOpenChange={(next) => {
          if (!next) setPreparing(null);
        }}
      />
    ) : null}
    </>
  );
}
