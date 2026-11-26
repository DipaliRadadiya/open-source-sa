import { useState } from "react";
import { useTranslations, useFormatter } from "next-intl";
import { toast } from "sonner";
import { History, Check } from "lucide-react";
import { cn } from "@/lib/utils";
import { restoreFileContent } from "@/lib/api/files";
import { apiMessage } from "@/lib/api/error-message";
import { parseApiWallClock } from "@/lib/format/api-date";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Caution } from "@/components/ui/caution";
import { SafetyCopySwitch } from "@/components/backups/safety-copy-switch";
import { HoverHint } from "@/components/ui/hover-hint";

export function RestoreFileBackupDialog({ appId, path, backups = [], open, onOpenChange, onRestored }) {
  const t = useTranslations("applications.files");
  const tc = useTranslations("common");
  const format = useFormatter();
  const [selected, setSelected] = useState(null);
  const [safetyCopy, setSafetyCopy] = useState(true);
  const [busy, setBusy] = useState(false);

  function handleOpenChange(next) {
    if (busy) return;
    if (!next) {
      setSelected(null);
      setSafetyCopy(true);
    }
    onOpenChange?.(next);
  }

  async function onRestore() {
    if (!selected) return;
    setBusy(true);
    try {
      await restoreFileContent(appId, path, selected, { safetyCopy });
      toast.success(t("restore.done"));
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
      description={safetyCopy ? t("restore.subtitle") : t("restore.subtitleNoCopy")}
      cancelLabel={t("cancel")}
      confirmLabel={t("restore.action")}
      confirmDisabled={!selected}
      pending={busy}
      onConfirm={onRestore}
      className="sm:!max-w-lg"
    >
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
              <HoverHint content={backup.name}><span className="min-w-0 text-sm">
                {when ? format.dateTime(when, { dateStyle: "medium", timeStyle: "medium", timeZone: "UTC" }) : backup.name}
              </span></HoverHint>
              {active ? <Check className="size-4 shrink-0 text-primary" /> : null}
            </button>
          );
        })}
      </div>

      <SafetyCopySwitch
        id="file-restore-safety-copy"
        checked={safetyCopy}
        onCheckedChange={setSafetyCopy}
        disabled={busy}
        label={tc("keepFileCopy")}
      />
      {!safetyCopy ? (
        <Caution tone="destructive" size="md">
          <p>{tc("keepFileCopyOff")}</p>
        </Caution>
      ) : null}
    </ConfirmDialog>
  );
}
