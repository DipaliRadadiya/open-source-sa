import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { CircleStop, TriangleAlert } from "lucide-react";
import { killProcess } from "@/lib/api/server-metrics";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { apiMessage } from "@/lib/api/error-message";

/**
 * Stop one process.
 *
 * Always confirms: stopping the wrong thing can take a site or a database down
 * and there is no undo. The dialog names the process and its PID, because a
 * table row is easy to mis-click and "are you sure?" alone doesn't help you
 * check.
 *
 * TERM first, KILL only as a follow-up. TERM lets a process flush and close
 * files; KILL doesn't. Offering "Force stop" up front would make the destructive
 * option the convenient one.
 */
export function KillProcessButton({ process, canManage }) {
  const t = useTranslations("serverDashboard");
  const { refreshThen } = useRefresh();
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);
  // Only offered once TERM has been tried and the process is still there.
  const [offerForce, setOfferForce] = useState(false);

  async function run(signal) {
    setPending(true);
    try {
      await killProcess(process.pid, signal);
      // Said once the list no longer shows it: announced on the API's answer,
      // "sh stopped" sat over a row still claiming 100% CPU for seconds.
      refreshThen(() => {
        toast.success(t("kill.stopped", { command: shortCommand(process.command) }));
        setConfirming(false);
        setOfferForce(false);
        setPending(false);
      });
      return;
    } catch (error) {
      const status = error.response?.status;

      if (status === 404) {
        // Already gone — but NOT a success. PIDs are recycled, so the row may
        // now point at a different process entirely; the honest move is to say
        // nothing was stopped and reload the list.
        refreshThen(() => {
          toast.info(t("kill.alreadyGone"));
          setConfirming(false);
          setPending(false);
        });
        return;
      }

      if (status === 422) {
        // A permanent refusal — PID 1, a kernel thread, the panel's own PHP, a
        // protected service. Retrying will never work, so don't offer it.
        toast.error(apiMessage(error, t("kill.refused")));
        setConfirming(false);
        setPending(false);
        return;
      }

      toast.error(
        apiMessage(error, t("kill.failed")),
      );
      // The signal didn't land. KILL is the next thing to try, so surface it now
      // rather than making the user reopen the dialog.
      if (signal === "TERM") setOfferForce(true);
    }
    setPending(false);
  }

  const stopButton = (
    <Button
      variant="ghost"
      size="icon"
      // Same red square the Services page uses for Stop. A grey circle read as
      // a generic target — "stop" should be one shape, and one colour,
      // everywhere in the product.
      className="size-8 text-destructive hover:bg-destructive/10 hover:text-destructive"
      disabled={!canManage}
      onClick={() => {
        setOfferForce(false);
        setConfirming(true);
      }}
      aria-label={t("kill.action")}
    >
      {/* A circle around the square. A bare outlined square at 16px is the
          same glyph as an unticked checkbox, and in destructive red on a table
          row it read as a rendering fault rather than a control. */}
      <CircleStop className="size-4" />
    </Button>
  );

  return (
    <>
      {/* Not permitted: ReasonTooltip, which also opens on a tap — the plain
          Radix tooltip this used never did, so a phone showed a dead red
          button. It supplies the reason through context, so the Button does
          not add a second tooltip of its own. */}
      {canManage ? (
        <Tooltip>
          <TooltipTrigger asChild>{stopButton}</TooltipTrigger>
          <TooltipContent>{t("kill.action")}</TooltipContent>
        </Tooltip>
      ) : (
        <ReasonTooltip reason={t("kill.noPermission")}>{stopButton}</ReasonTooltip>
      )}

      <ConfirmDialog
        open={confirming}
        onOpenChange={(open) => {
          if (pending) return;
          setConfirming(open);
          if (!open) setOfferForce(false);
        }}
        icon={TriangleAlert}
        tone="destructive"
        title={t("kill.title", { command: shortCommand(process.command) })}
        description={
          offerForce
            ? t("kill.forceDescription")
            : t("kill.description", { pid: process.pid, user: process.user || "—" })
        }
        cancelLabel={t("kill.cancel")}
        confirmLabel={offerForce ? t("kill.force") : t("kill.confirm")}
        confirmVariant="destructive"
        pending={pending}
        onConfirm={() => run(offerForce ? "KILL" : "TERM")}
      />
    </>
  );
}

// The full command line can be hundreds of characters; a dialog title needs the
// part that identifies it.
function shortCommand(command) {
  const first = String(command ?? "").trim().split(/\s+/)[0] || "";
  const name = first.split("/").pop();
  return name || String(command ?? "");
}
