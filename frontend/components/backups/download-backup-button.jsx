"use client";

import { useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { Download, Loader2 } from "lucide-react";
import { formatBytes } from "@/lib/format/bytes";
import { BACKUP_IN_FLIGHT } from "@/lib/schemas/backup";
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

  // A run still in flight has not written an archive yet — the API answers 422
  // for exactly this, and meeting that after a click is worse than a button
  // that says why up front.
  const blocker = !canDownload
    ? t("blocked.noPermission")
    : BACKUP_IN_FLIGHT.includes(backup.status)
      ? t("blocked.inFlight")
      : null;

  async function download() {
    setPending(true);
    try {
      const response = await fetchBackupDownload(backup.id);
      const url = response.data?.download?.url;
      if (!url) throw new Error("missing");
      toast.success(t("started"));
      // A plain navigation, not fetch(): our interceptor's headers are not part
      // of what the signature covers, and the bucket is cross-origin.
      window.location.href = url;
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
