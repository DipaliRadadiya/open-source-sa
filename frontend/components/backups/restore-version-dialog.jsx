import { useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { History, Check } from "lucide-react";
import { cn } from "@/lib/utils";
import { apiMessage } from "@/lib/api/error-message";
import { parseApiWallClock } from "@/lib/format/api-date";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Caution } from "@/components/ui/caution";
import { HoverHint } from "@/components/ui/hover-hint";
import { SafetyCopySwitch } from "@/components/backups/safety-copy-switch";

// Pick one saved copy (.env backup, file version) and put it back.
// `restore` gets { backup, restart, safetyCopy } and returns the API answer for onRestored.
export function RestoreVersionDialog({
  namespace,
  backups = [],
  open,
  onOpenChange,
  restore,
  onRestored,
  safetyCopyId,
  nameOnHover = false,
  restartOption = false,
}) {
  const tx = useTranslations(namespace);
  const tc = useTranslations("common");
  const format = useFormatter();
  const [selected, setSelected] = useState(null);
  const [restart, setRestart] = useState(false);
  const [safetyCopy, setSafetyCopy] = useState(true);
  const [busy, setBusy] = useState(false);

  function handleOpenChange(next) {
    if (busy) return;
    if (!next) {
      setSelected(null);
      setRestart(false);
      setSafetyCopy(true);
    }
    onOpenChange?.(next);
  }

  async function onRestore() {
    if (!selected) return;
    setBusy(true);
    try {
      const data = await restore({ backup: selected, restart, safetyCopy });
      toast.success(tx("restore.done"));
      onRestored?.(data);
      handleOpenChange(false);
    } catch (error) {
      toast.error(apiMessage(error, tx("restore.failed")));
    } finally {
      setBusy(false);
    }
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={handleOpenChange}
      icon={History}
      title={tx("restore.title")}
      description={safetyCopy ? tx("restore.subtitle") : tx("restore.subtitleNoCopy")}
      cancelLabel={tx("cancel")}
      confirmLabel={tx("restore.action")}
      confirmDisabled={!selected}
      pending={busy}
      onConfirm={onRestore}
      className="sm:!max-w-lg"
    >
      <div
        role="radiogroup"
        aria-label={tx("restore.title")}
        className="-m-1 max-h-[min(20rem,45dvh)] space-y-2 overflow-y-auto p-1"
      >
        {backups.map((backup) => {
          const active = selected === backup.name;
          const when = parseApiWallClock(backup.created_at);
          const date = when
            ? format.dateTime(when, { dateStyle: "medium", timeStyle: "medium", timeZone: "UTC" })
            : null;
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
              {nameOnHover ? (
                <HoverHint content={backup.name}>
                  <span className="min-w-0 text-sm">{date ?? backup.name}</span>
                </HoverHint>
              ) : (
                <span className="min-w-0">
                  <span className="block text-sm font-medium">{date ?? backup.created_at ?? backup.name}</span>
                  <span className="block truncate font-mono text-xs text-muted-foreground">{backup.name}</span>
                </span>
              )}
              {active ? <Check className="size-4 shrink-0 text-primary" /> : null}
            </button>
          );
        })}
      </div>

      <SafetyCopySwitch
        id={safetyCopyId}
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

      {restartOption ? (
        <div className="flex items-start gap-2.5 rounded-lg border p-3">
          <Checkbox
            id="restore-restart"
            checked={restart}
            onCheckedChange={(v) => setRestart(v === true)}
            className="mt-0.5"
          />
          <Label htmlFor="restore-restart" className="text-sm font-normal leading-relaxed" hint={tx("restore.restartHint")}>
            {tx("restore.restart")}
          </Label>
        </div>
      ) : null}
    </ConfirmDialog>
  );
}
