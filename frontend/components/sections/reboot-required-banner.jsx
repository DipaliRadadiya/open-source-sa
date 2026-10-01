"use client";

import { useState } from "react";
import Link from "@/components/ui/app-link";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Power, RotateCcw } from "lucide-react";
import { rebootServer } from "@/lib/api/settings";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { useServerRestart } from "@/components/sections/server-restart-overlay";

// Restarts with the settings page's confirmation; scheduling stays there. `canManage`
// is `setting:manage`: view-only users see the notice without a button.
export function RebootRequiredBanner({ canManage }) {
  const t = useTranslations("rebootBanner");
  const { start } = useServerRestart();
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);

  async function confirm() {
    setPending(true);
    try {
      // `0` = now. The API answers 202 and the machine goes down shortly after, so this
      // is an acknowledgement, not a completion.
      await rebootServer(0);
      setConfirming(false);
      // Not a toast (it would fade while the server goes down), and not
      // router.refresh() (a render request to a server shutting down).
      start();
    } catch (error) {
      // The restart never began, so there is nothing to watch.
      toast.error(apiMessage(error, t("failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    // Same as the header bar: h-7 buttons are a small target on a phone.
    <div className="relative flex flex-wrap items-center justify-center gap-x-3 gap-y-1.5 border-b border-warning/30 bg-background px-4 py-2 text-sm text-foreground before:pointer-events-none before:absolute before:inset-0 before:bg-warning/15 before:content-[''] max-sm:[&_a]:min-h-11 max-sm:[&_button]:min-h-11">
      <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5 font-medium">
        <RotateCcw className="size-4 shrink-0 text-warning" />
        {t("message")}
      </span>

      {canManage ? (
        <span className="flex items-center gap-2">
          <Button
            variant="destructive"
            size="sm"
            className="h-7"
            onClick={() => setConfirming(true)}
          >
            {t("action")}
          </Button>

          {/* Scheduling and other restart options stay on their own page. */}
          <Button asChild variant="ghost" size="sm" className="h-7">
            <Link href="/settings/maintenance">{t("options")}</Link>
          </Button>
        </span>
      ) : null}

      <ConfirmDialog
        open={confirming}
        onOpenChange={(open) => !open && setConfirming(false)}
        icon={Power}
        tone="destructive"
        title={t("confirmTitle")}
        description={t("confirmDescription")}
        cancelLabel={t("confirmCancel")}
        confirmLabel={t("confirmSubmit")}
        pending={pending}
        onConfirm={confirm}
      />
    </div>
  );
}
