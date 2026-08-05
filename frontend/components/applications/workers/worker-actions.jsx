"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Play, Square, RotateCw, Loader2, TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { runWorkerAction } from "@/lib/api/workers";
import { DISRUPTIVE_ACTIONS } from "@/lib/schemas/worker";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

const ACTION_META = {
  start: { icon: Play, tone: "text-success hover:bg-success/10 hover:text-success" },
  restart: { icon: RotateCw, tone: "text-warning hover:bg-warning/10 hover:text-warning" },
  stop: { icon: Square, tone: "text-destructive hover:bg-destructive/10 hover:text-destructive" },
};

// Fixed slots so the same action sits at the same x on every row. start and
// restart never co-occur — start only appears when nothing is running.
const SLOTS = [["start", "restart"], ["stop"]];

const BY_STATE = {
  running: ["restart", "stop"],
  degraded: ["restart", "stop"],
  stopped: ["start"],
};

/**
 * Per-row start/stop/restart. Edit/Delete live in the row ⋯ menu instead —
 * five raw icons on every row reads as clutter next to the app's other lists,
 * where secondary actions are always tucked behind ⋯.
 */
export function WorkerActions({ worker, appId, canManage, onBusyChange, reserveSlots = true }) {
  const t = useTranslations("applications.workers");
  const router = useRouter();
  const [pending, setPending] = useState(null);
  const [confirming, setConfirming] = useState(null);

  function setBusyAction(action) {
    setPending(action);
    onBusyChange?.(action);
  }

  const actions = BY_STATE[worker.state] ?? ["start"];
  const busy = pending !== null;

  async function run(action) {
    setBusyAction(action);
    try {
      await runWorkerAction(appId, worker.id, action);
      toast.success(t(`toast.${action}`, { name: worker.name }));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t(`error.${action}`, { name: worker.name })));
    } finally {
      setBusyAction(null);
      setConfirming(null);
    }
  }

  function trigger(action) {
    if (DISRUPTIVE_ACTIONS.includes(action)) setConfirming(action);
    else run(action);
  }

  return (
    <div className="flex items-center justify-end gap-0.5">
      {SLOTS.map((slot) => {
        const action = slot.find((a) => actions.includes(a));
        if (!action) return reserveSlots ? <Slot key={slot[0]} /> : null;

        const meta = ACTION_META[action];
        const Icon = meta.icon;
        const label = t(`actions.${action}`);
        const disabled = !canManage || busy;

        return (
          <Tooltip key={slot[0]}>
            <TooltipTrigger asChild>
              <span tabIndex={disabled ? 0 : -1} className="inline-flex">
                <Button
                  variant="ghost"
                  size="icon"
                  className={cn("size-8", meta.tone)}
                  disabled={disabled}
                  onClick={() => trigger(action)}
                  aria-label={label}
                >
                  {pending === action ? (
                    <Loader2 className="size-4 animate-spin" />
                  ) : (
                    <Icon className="size-4" />
                  )}
                </Button>
              </span>
            </TooltipTrigger>
            <TooltipContent>{canManage ? label : t("noPermission")}</TooltipContent>
          </Tooltip>
        );
      })}

      <ConfirmDialog
        open={confirming !== null}
        onOpenChange={(open) => !open && setConfirming(null)}
        icon={TriangleAlert}
        tone="destructive"
        title={confirming ? t(`confirm.${confirming}.title`, { name: worker.name }) : ""}
        description={confirming ? t(`confirm.${confirming}.description`) : ""}
        cancelLabel={t("cancel")}
        confirmLabel={confirming ? t(`actions.${confirming}`) : ""}
        confirmVariant="destructive"
        pending={busy}
        onConfirm={() => run(confirming)}
      />
    </div>
  );
}

function Slot({ children }) {
  return <span className="inline-flex size-8 items-center justify-center">{children}</span>;
}
