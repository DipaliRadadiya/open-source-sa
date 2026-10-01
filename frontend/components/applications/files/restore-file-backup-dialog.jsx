import { useState } from "react";
import { useTranslations, useFormatter } from "next-intl";
import { toast } from "sonner";
import { History, Check } from "lucide-react";
import { cn } from "@/lib/utils";
import { restoreFileContent } from "@/lib/api/files";
import { apiMessage } from "@/lib/api/error-message";
import { parseApiWallClock } from "@/lib/format/api-date";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

// Same shape as the .env editor's restore dialog, scoped to one file. Restoring
// first backs up the current version, so a wrong pick is undoable.
// Built on ConfirmDialog (its `children` slot holds the picker) so it stays
// consistent with other confirmations.
export function RestoreFileBackupDialog({ appId, path, backups = [], open, onOpenChange, onRestored }) {
  const t = useTranslations("applications.files");
  const format = useFormatter();
  const [selected, setSelected] = useState(null);
  const [busy, setBusy] = useState(false);

  function handleOpenChange(next) {
    if (busy) return;
    if (!next) setSelected(null);
    onOpenChange?.(next);
  }

  async function onRestore() {
    if (!selected) return;
    setBusy(true);
    try {
      await restoreFileContent(appId, path, selected);
      toast.success(t("restore.done"));
      // The editor re-reads the file itself; this endpoint returns no content.
      onRestored?.();
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
      // Wider than a yes/no confirmation: the body is a list to pick from.
      className="sm:!max-w-lg"
    >
      {/* A radio group so screen readers know which version is picked. Bounded so a long
          list keeps the buttons in view. */}
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
              {/* The saved time distinguishes versions; the `.bak-…` file name is kept for hover. */}
              <span className="min-w-0 text-sm" title={backup.name}>
                {when ? format.dateTime(when, { dateStyle: "medium", timeStyle: "medium", timeZone: "UTC" }) : backup.name}
              </span>
              {active ? <Check className="size-4 shrink-0 text-primary" /> : null}
            </button>
          );
        })}
      </div>
    </ConfirmDialog>
  );
}
