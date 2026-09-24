"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Lock } from "lucide-react";
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
        description={t("body", { path: path ?? "" })}
        cancelLabel={t("cancel")}
        confirmLabel={busy ? t("locking") : t("confirm")}
        pending={busy}
        error={error}
        onConfirm={lock}
      />
    </>
  );
}
