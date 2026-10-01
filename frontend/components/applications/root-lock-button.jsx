"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Ban, FolderLock, Lock, ShieldCheck, CircleCheck } from "lucide-react";
import { lockApplicationRoot } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";

// A refusal keeps the dialog open with the server's reason, which names the fix.
export function RootLockButton({ applicationId, path, canManage = true }) {
  const t = useTranslations("applications.rootLock");
  const tApp = useTranslations("applications");
  const { pending: refreshing, refreshThen } = useRefresh();
  const [open, setOpen] = useState(false);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);
  const busy = pending || refreshing;

  async function lock() {
    setPending(true);
    setError(null);
    try {
      await lockApplicationRoot(applicationId);
      // Toast now: the re-read marks the row Locked, which unmounts this button and
      // any pending callback.
      toast.success(t("done"));
      refreshThen(() => setOpen(false));
    } catch (e) {
      setError(apiMessage(e, t("failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <>
      <ReasonTooltip reason={canManage ? null : tApp("noPermission")}>
        <Button
          type="button"
          size="sm"
          disabled={!canManage}
          onClick={() => {
            setError(null);
            setOpen(true);
          }}
        >
          <Lock className="size-4" />
          {t("lock")}
        </Button>
      </ReasonTooltip>
      <ConfirmDialog
        open={open}
        onOpenChange={setOpen}
        icon={Lock}
        tone="warning"
        title={t("title")}
        description={t("intro")}
        cancelLabel={t("cancel")}
        confirmLabel={busy ? t("locking") : t("confirm")}
        pending={busy}
        error={error}
        onConfirm={lock}
      >
        {path ? (
          <div className="flex items-start gap-2.5 rounded-lg border bg-muted/40 px-3 py-2.5">
            <FolderLock className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
            <div className="min-w-0">
              <p className="text-xs text-muted-foreground">{t("folderLabel")}</p>
              <p className="font-mono text-sm break-all">{path}</p>
            </div>
          </div>
        ) : null}
        <ul className="space-y-2.5 text-sm">
          <li className="flex items-start gap-2.5">
            <ShieldCheck className="mt-0.5 size-4 shrink-0 text-success" aria-hidden />
            <span>{t("effectOwner")}</span>
          </li>
          <li className="flex items-start gap-2.5">
            <Ban className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden />
            <span>{t("effectNoChanges")}</span>
          </li>
          <li className="flex items-start gap-2.5">
            <CircleCheck className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
            <span>{t("effectPublic")}</span>
          </li>
        </ul>
      </ConfirmDialog>
    </>
  );
}
