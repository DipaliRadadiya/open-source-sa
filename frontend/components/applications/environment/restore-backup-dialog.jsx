import { useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { History, Check } from "lucide-react";
import { cn } from "@/lib/utils";
import { restoreEnvironment } from "@/lib/api/environment";
import { apiMessage } from "@/lib/api/error-message";
import { parseApiWallClock } from "@/lib/format/api-date";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

/**
 * Restore the .env from one of the recent backups. Restoring first backs up the
 * current file, so picking the wrong one is itself undoable — said in the copy
 * so it doesn't feel like a one-way door.
 *
 * On ConfirmDialog rather than raw Dialog parts: a confirmation with a picker
 * and an opt-in checkbox is still a confirmation, and the shared dialog's body
 * slot takes both. Rebuilding the shell here let it drift from every other one.
 */
export function RestoreBackupDialog({
  appId,
  backups = [],
  requiresRestart = false,
  open,
  onOpenChange,
  onRestored,
}) {
  const t = useTranslations("applications.environment");
  const format = useFormatter();
  const [selected, setSelected] = useState(null);
  const [restart, setRestart] = useState(false);
  const [busy, setBusy] = useState(false);

  function handleOpenChange(next) {
    if (busy) return;
    if (!next) {
      setSelected(null);
      setRestart(false);
    }
    onOpenChange?.(next);
  }

  async function onRestore() {
    if (!selected) return;
    setBusy(true);
    try {
      const data = await restoreEnvironment(appId, {
        backup: selected,
        restart,
      });
      toast.success(t("restore.done"));
      onRestored?.(data?.environment ?? null);
      handleOpenChange(false);
    } catch (error) {
      toast.error(apiMessage(error, t("restore.failed")));
    } finally {
      setBusy(false);
    }
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={handleOpenChange}
      icon={History}
      title={t("restore.title")}
      description={t("restore.subtitle")}
      cancelLabel={t("cancel")}
      confirmLabel={t("restore.action")}
      confirmDisabled={!selected}
      pending={busy}
      onConfirm={onRestore}
      // Wider than a yes/no confirmation: the body lists backup filenames.
      className="sm:!max-w-lg"
    >
      {/* A choice of one, so a radio group: a row of plain buttons told a
          screen reader nothing about which copy was picked. The saved time
          leads — it is what people choose by — and the file name follows for
          anyone matching it against the server. */}
      {/* Bounded: twenty backups pushed Restore and Cancel a screen below
          the list on a phone. The padding keeps focus rings inside the clip. */}
      <div
        role="radiogroup"
        aria-label={t("restore.title")}
        className="-m-1 max-h-[min(20rem,45dvh)] space-y-2 overflow-y-auto p-1"
      >
        {backups.map((backup) => {
          const active = selected === backup.name;
          const when = parseApiWallClock(backup.created_at);
          return (
            <button
              key={backup.name}
              type="button"
              role="radio"
              aria-checked={active}
              onClick={() => setSelected(backup.name)}
              className={cn(
                "flex w-full items-center justify-between gap-3 rounded-lg border px-3 py-2 text-left transition-colors",
                active ? "border-primary bg-primary/5" : "hover:bg-muted/50",
              )}
            >
              <span className="min-w-0">
                <span className="block text-sm font-medium">
                  {when
                    ? format.dateTime(when, { dateStyle: "medium", timeStyle: "medium", timeZone: "UTC" })
                    : (backup.created_at ?? backup.name)}
                </span>
                <span className="block truncate font-mono text-xs text-muted-foreground">
                  {backup.name}
                </span>
              </span>
              {active ? (
                <Check className="size-4 shrink-0 text-primary" />
              ) : null}
            </button>
          );
        })}
      </div>

      {requiresRestart ? (
        <div className="flex items-start gap-2.5 rounded-lg border p-3">
          <Checkbox
            id="restore-restart"
            checked={restart}
            onCheckedChange={(v) => setRestart(v === true)}
            className="mt-0.5"
          />
          <Label
            htmlFor="restore-restart"
            className="text-sm font-normal leading-relaxed"
           hint={t("restore.restartHint")}>
            {t("restore.restart")}
          </Label>
        </div>
      ) : null}
    </ConfirmDialog>
  );
}
