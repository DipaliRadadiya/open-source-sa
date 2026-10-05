import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2, Undo2 } from "lucide-react";
import { fetchBackup } from "@/lib/api/backups";
import { apiMessage } from "@/lib/api/error-message";
import { RESTORE_IN_FLIGHT } from "@/lib/schemas/backup";
import { Button } from "@/components/ui/button";
import { RestoreDialog } from "@/components/backups/restore-dialog";
import { useRestoreWatch } from "@/components/backups/restore-watch";

// The restore row names its exact safety copy, so Undo opens the restore of that copy,
// as the banner's Undo does — not the site's whole history.
export function UndoRestoreButton({ restore, canRestore = false, className }) {
  const t = useTranslations("backups.restores");
  const tp = useTranslations("backups.progress");
  const th = useTranslations("backups.history");
  const tb = useTranslations("backups.application");
  const router = useRouter();
  const { active, start } = useRestoreWatch();
  const [loading, setLoading] = useState(false);
  const [backup, setBackup] = useState(null);

  const restoreRunning = RESTORE_IN_FLIGHT.includes(active?.status);

  async function open() {
    setLoading(true);
    try {
      const response = await fetchBackup(restore.safety_backup_id);
      const found = response.data?.backup;
      if (!found) throw new Error("missing");
      // Put back only what this restore replaced (e.g. database only).
      setBackup({ ...found, application_domain: restore.application_domain, preferred_type: restore.type });
    } catch (error) {
      // Only the newest two safety copies are kept, so an older one can be gone.
      if (error?.response?.status === 404) toast.info(t("safetyGone"));
      else toast.error(apiMessage(error, tp("undoFailed")));
    } finally {
      setLoading(false);
    }
  }

  return (
    <>
      <Button
        variant="outline"
        size="sm"
        className={className}
        onClick={open}
        disabled={!canRestore || restoreRunning || loading}
        disabledReason={!canRestore ? th("noPermission") : restoreRunning ? tb("restoreRunning") : null}
      >
        {loading ? <Loader2 className="size-4 animate-spin" /> : <Undo2 className="size-4" />}
        {t("undo")}
      </Button>
      <RestoreDialog
        key={backup?.id}
        backup={backup}
        open={Boolean(backup)}
        onOpenChange={(next) => (next ? null : setBackup(null))}
        onStarted={(started) => {
          setBackup(null);
          if (started) start(started);
          router.refresh();
        }}
      />
    </>
  );
}
