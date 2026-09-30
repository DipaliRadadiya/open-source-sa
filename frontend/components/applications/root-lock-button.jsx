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

/**
 * Lock the folder of a site server sync adopted.
 *
 * Sync never changes ownership on its own, so this is the step that makes an
 * adopted site's folder what the panel gives every site it creates. Asked
 * first because it changes what the site user can do: nothing can be added,
 * removed or renamed directly in that folder afterwards.
 *
 * A refusal keeps the dialog open with the server's reason, which names what
 * to fix on the server. As a toast it would be gone before anyone read the
 * `chmod` it suggests.
 */
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
      // Said now, not after the re-read: the re-read turns the row to Locked,
      // which removes this button — and a callback waiting on it with it.
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
        {/* One paragraph used to carry the path, the ownership change, the
            restriction and the reassurance; split so each can be scanned —
            which folder, then what changes, then what does not. */}
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
