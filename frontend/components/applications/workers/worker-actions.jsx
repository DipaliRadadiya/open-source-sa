import { useState } from "react";
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
export function WorkerActions({ worker, appId, canManage, onBusyChange, onUpdated, reserveSlots = true }) {
  const t = useTranslations("applications.workers");
  const [pending, setPending] = useState(null);
  const [confirming, setConfirming] = useState(null);

  function setBusyAction(action) {
    setPending(action);
    onBusyChange?.(action);
  }

  const actions = BY_STATE[worker.state] ?? ["start"];
  const busy = pending !== null;

  /**
   * What actually happened, in the worker's own words.
   *
   * The endpoint answers with the worker as systemd reports it after the
   * action — the controller's "state is never stored: every response asks
   * systemd what is actually running". So the outcome is knowable, and saying
   * "Starting…" on a green tick and stopping there was throwing that away.
   *
   * The unhappy case is the one worth having: `systemctl start` succeeds for a
   * unit that starts and dies immediately, which is what a mistyped command
   * does. `apply()` guards against that with `is-active`; `start()` does not.
   * So a worker can come back still stopped from a "successful" start, and
   * that has to read as a problem rather than a success.
   */
  function reportOutcome(id, action, next) {
    const name = worker.name;
    // No readable state means we genuinely do not know the outcome; the
    // request was accepted, and claiming more than that would be a guess.
    if (!next?.state) {
      toast.info(t(`toast.${action}`, { name }), { id });
      return;
    }
    if (action === "stop") {
      if (next.state === "stopped") toast.success(t("toast.stopped", { name }), { id });
      else toast.warning(t("toast.stillRunning", { name }), { id });
      return;
    }
    if (next.state === "running") {
      toast.success(t("toast.running", { name }), { id });
    } else if (next.state === "degraded") {
      toast.warning(
        t("toast.degraded", { name, running: next.running, processes: next.processes }),
        { id },
      );
    } else {
      toast.warning(t("toast.notRunning", { name }), { id });
    }
  }

  async function run(action) {
    setBusyAction(action);
    // One toast for the whole action: it opens as a spinner and is replaced in
    // place by the outcome, so there is never a moment with nothing to read.
    const id = toast.loading(t(`toast.${action}`, { name: worker.name }));
    try {
      const { data } = await runWorkerAction(appId, worker.id, action);
      const next = data?.worker;
      // Applied before the busy flag clears, so the badge goes straight from
      // "Starting…" to the new state instead of flashing the old one back.
      if (next?.id === worker.id) onUpdated?.(next);
      reportOutcome(id, action, next);
      // Deliberately NOT router.refresh(): that re-reads systemd a second time
      // and its answer landed *after* this one, overwriting the row — measured
      // as a badge going "Starting…" → "Stopped" → "Running", which is the
      // flicker this was meant to remove. The panel already polls both the
      // workers and the checks alert, so nothing here needs a server render.
    } catch (error) {
      toast.error(apiMessage(error, t(`error.${action}`, { name: worker.name })), { id });
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
