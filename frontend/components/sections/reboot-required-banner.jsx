"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Power, RotateCcw } from "lucide-react";
import { rebootServer } from "@/lib/api/settings";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

/**
 * "This server is waiting on a restart", on every page — and the restart.
 *
 * It was reported in two places you had to already be looking at: a badge on
 * the dashboard's info card, and a dot on one settings tab. Both are easy to go
 * months without seeing, and until the restart happens the patch that was
 * installed is not actually protecting anything.
 *
 * The button restarts rather than navigating. A banner that only points at
 * another page makes the reader do the work twice, and the page it pointed at
 * offers exactly this action behind exactly this confirmation. The delay picker
 * stays there, reachable through "More options", because a banner is the wrong
 * place to schedule something.
 *
 * `canManage` is `setting:manage`, not `view` — this is the restart itself now,
 * so someone who can only look gets the notice with no button at all.
 */
export function RebootRequiredBanner({ canManage }) {
  const t = useTranslations("rebootBanner");
  const router = useRouter();
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);

  async function confirm() {
    setPending(true);
    try {
      // `0` = now. The API answers 202 and the machine goes away shortly after,
      // so this response is an acknowledgement, not a completion.
      await rebootServer(0);
      toast.success(t("started"));
      setConfirming(false);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    // Same reason as the header bar: these are h-7 buttons, which is a small
    // target on a phone for an action this consequential.
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

          {/* Scheduling it for later, and everything else about restarts,
              stays on the page built for it. */}
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
