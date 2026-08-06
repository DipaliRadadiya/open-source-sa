"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { History, Loader2, Check } from "lucide-react";
import { cn } from "@/lib/utils";
import { restoreFileContent } from "@/lib/api/files";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

// Same shape as the .env editor's restore dialog, scoped to one file's own
// path instead of a fixed file. Restoring itself takes a backup of what was
// there first, so picking the wrong one is itself undoable.
export function RestoreFileBackupDialog({ appId, path, backups = [], open, onOpenChange, onRestored }) {
  const t = useTranslations("applications.files");
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
      const { data } = await restoreFileContent(appId, path, selected);
      toast.success(t("restore.done"));
      onRestored?.(data?.file ?? null);
      handleOpenChange(false);
    } catch (error) {
      toast.error(apiMessage(error, t("restore.failed")));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <div className="flex items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
              <History className="size-5" />
            </span>
            <DialogTitle>{t("restore.title")}</DialogTitle>
          </div>
          <DialogDescription className="pt-1">{t("restore.subtitle")}</DialogDescription>
        </DialogHeader>

        <div className="space-y-2">
          {backups.map((backup) => {
            const active = selected === backup.name;
            return (
              <button
                key={backup.name}
                type="button"
                onClick={() => setSelected(backup.name)}
                className={cn(
                  "flex w-full items-center justify-between gap-3 rounded-lg border px-3 py-2 text-left transition-colors",
                  active ? "border-primary bg-primary/5" : "hover:bg-muted/50",
                )}
              >
                <span className="min-w-0">
                  <span className="block truncate font-mono text-xs">{backup.name}</span>
                  {backup.created_at_human ?? backup.created_at ? (
                    <span className="block text-xs text-muted-foreground">
                      {backup.created_at_human ?? backup.created_at}
                    </span>
                  ) : null}
                </span>
                {active ? <Check className="size-4 shrink-0 text-primary" /> : null}
              </button>
            );
          })}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => handleOpenChange(false)} disabled={busy}>
            {t("cancel")}
          </Button>
          <Button onClick={onRestore} disabled={!selected || busy}>
            {busy ? <Loader2 className="size-4 animate-spin" /> : null}
            {t("restore.action")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
