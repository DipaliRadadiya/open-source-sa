import { useEffect, useRef, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2, PauseCircle, PowerOff } from "lucide-react";
import { deleteBackupTarget, saveBackupTarget } from "@/lib/api/backups";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

/**
 * Remove an application's backup schedule.
 *
 * The API will not remove it while backups exist unless they are deleted
 * with it, so when there are any the checkbox is required, not optional —
 * an optional one would promise a "keep them" the server refuses. Keeping
 * them means pausing, which this dialog can do in place.
 *
 * `count` is null when the history read failed: the box then names no number
 * rather than claiming there are none.
 */
export function TurnOffBackupsDialog({ open, onOpenChange, application, target, count }) {
  const t = useTranslations("backups.application.turnOff");
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [savingPause, setSavingPause] = useState(false);
  const [deleteBackups, setDeleteBackups] = useState(false);
  /*
   * Open until the page behind has re-read. Closing on the API's answer left
   * the old card, and its Turn off button, on screen for two to four seconds
   * on a real server, under a toast saying it was done.
   */
  const [refreshing, startRefresh] = useTransition();
  const doneMessage = useRef(null);
  const [action, setAction] = useState(null);
  useEffect(() => {
    if (refreshing || !doneMessage.current) return;
    toast.success(doneMessage.current);
    doneMessage.current = null;
    onOpenChange(false);
  }, [refreshing, onOpenChange]);
  const working = pending || savingPause || refreshing;
  const pauseBusy = savingPause || (refreshing && action === "pause");
  const hasBackups = count === null || count > 0;
  // Already paused: offering to pause it is no alternative at all.
  const canPause = Boolean(target?.enabled) && target?.frequency !== "manual";

  function handleOpenChange(next) {
    if (working) return;
    if (!next) setDeleteBackups(false);
    onOpenChange(next);
  }

  async function confirm() {
    setAction("delete");
    setPending(true);
    try {
      await deleteBackupTarget(application.id, { deleteBackups: hasBackups && deleteBackups });
      doneMessage.current =
        hasBackups && count
          ? t("doneWithBackups", { name: application.name, count })
          : t("done", { name: application.name });
      startRefresh(() => router.refresh());
    } catch (error) {
      toast.error(apiMessage(error, t("failed")));
    } finally {
      setPending(false);
    }
  }

  /*
   * The target's own values sent back with `enabled: false`, which is what the
   * settings dialog's switch does. Same retention count on purpose: lowering it
   * deletes backups on save, and this exists to keep them.
   */
  async function pause() {
    setAction("pause");
    setSavingPause(true);
    try {
      await saveBackupTarget(application.id, {
        storage_destination_id: target.storage_destination_id,
        type: target.type,
        retention_count: target.retention_count,
        frequency: target.frequency,
        ...(target.schedule_time ? { schedule_time: target.schedule_time } : null),
        enabled: false,
        file_excludes: target.file_excludes ?? [],
        database_excludes: target.database_excludes ?? [],
      });
      setDeleteBackups(false);
      doneMessage.current = t("paused");
      startRefresh(() => router.refresh());
    } catch (error) {
      toast.error(apiMessage(error, t("pauseFailed")));
    } finally {
      setSavingPause(false);
    }
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={handleOpenChange}
      icon={PowerOff}
      tone="destructive"
      title={t("title", { name: application.name })}
      description={t("description")}
      cancelLabel={t("cancel")}
      confirmLabel={t("confirm")}
      confirmVariant="destructive"
      confirmDisabled={(hasBackups && !deleteBackups) || savingPause}
      pending={pending || (refreshing && action === "delete")}
      onConfirm={confirm}
    >
      {hasBackups ? (
        <div className="space-y-3">
          <div className="flex items-start gap-3 rounded-lg border bg-muted/40 p-3">
            <Checkbox
              id="turn-off-delete-backups"
              checked={deleteBackups}
              onCheckedChange={(value) => setDeleteBackups(value === true)}
              className="mt-0.5"
            />
            <div className="space-y-1">
              <Label htmlFor="turn-off-delete-backups" className="text-sm font-medium">
                {count === null ? t("deleteAll") : t("deleteCount", { count })}
              </Label>
              <p className="text-xs leading-5 text-muted-foreground">
                {t("deleteHint", { destination: target?.storage_destination_name ?? t("storage") })}
              </p>
            </div>
          </div>
          {canPause ? (
            <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3">
              <p className="min-w-48 flex-1 text-xs leading-5 text-muted-foreground">{t("keepHint")}</p>
              <Button type="button" variant="outline" size="sm" onClick={pause} disabled={pending || savingPause || refreshing}>
                {pauseBusy ? <Loader2 className="size-4 animate-spin" /> : <PauseCircle className="size-4" />}
                {t("pauseInstead")}
              </Button>
            </div>
          ) : null}
        </div>
      ) : null}
    </ConfirmDialog>
  );
}
