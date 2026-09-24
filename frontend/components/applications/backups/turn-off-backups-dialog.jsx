import { useState } from "react";
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
  const hasBackups = count === null || count > 0;
  // Already paused: offering to pause it is no alternative at all.
  const canPause = Boolean(target?.enabled) && target?.frequency !== "manual";

  function handleOpenChange(next) {
    if (pending || savingPause) return;
    if (!next) setDeleteBackups(false);
    onOpenChange(next);
  }

  async function confirm() {
    setPending(true);
    try {
      await deleteBackupTarget(application.id, { deleteBackups: hasBackups && deleteBackups });
      toast.success(
        hasBackups && count
          ? t("doneWithBackups", { name: application.name, count })
          : t("done", { name: application.name }),
      );
      setDeleteBackups(false);
      onOpenChange(false);
      router.refresh();
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
      toast.success(t("paused"));
      setDeleteBackups(false);
      onOpenChange(false);
      router.refresh();
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
      pending={pending}
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
              <Button type="button" variant="outline" size="sm" onClick={pause} disabled={pending || savingPause}>
                {savingPause ? <Loader2 className="size-4 animate-spin" /> : <PauseCircle className="size-4" />}
                {t("pauseInstead")}
              </Button>
            </div>
          ) : null}
        </div>
      ) : null}
    </ConfirmDialog>
  );
}
