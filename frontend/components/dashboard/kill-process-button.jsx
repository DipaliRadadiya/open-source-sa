import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { CircleStop, TriangleAlert } from "lucide-react";
import { killProcess } from "@/lib/api/server-metrics";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Caution } from "@/components/ui/caution";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { apiMessage } from "@/lib/api/error-message";

// Always confirms: there is no undo. TERM first; KILL only as a follow-up.
export function KillProcessButton({ process, canManage }) {
  const t = useTranslations("serverDashboard");
  const { refreshThen } = useRefresh();
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);
  // Only offered once TERM has been tried and the process is still there.
  const [offerForce, setOfferForce] = useState(false);
  const engine = databaseEngine(process.command);

  async function run(signal) {
    setPending(true);
    try {
      await killProcess(process.pid, signal);
      // Toast once the refreshed list no longer shows the process.
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
        // NOT a success: PIDs are recycled, so the row may now be a different process.
        refreshThen(() => {
          toast.info(t("kill.alreadyGone"));
          setConfirming(false);
          setPending(false);
        });
        return;
      }

      if (status === 422) {
        // Permanent refusal (PID 1, kernel thread, protected service): never offer a retry.
        toast.error(apiMessage(error, t("kill.refused")));
        setConfirming(false);
        setPending(false);
        return;
      }

      toast.error(
        apiMessage(error, t("kill.failed")),
      );
      // The signal didn't land; offer KILL now rather than making the user reopen the dialog.
      if (signal === "TERM") setOfferForce(true);
    }
    setPending(false);
  }

  const stopButton = (
    <Button
      variant="ghost"
      size="icon"
      // Same red stop styling the Services page uses.
      className="size-8 text-destructive hover:bg-destructive/10 hover:text-destructive"
      disabled={!canManage}
      onClick={() => {
        setOfferForce(false);
        setConfirming(true);
      }}
      aria-label={t("kill.action")}
    >
      {/* A circled square: a bare outlined square reads as an unticked checkbox. */}
      <CircleStop className="size-4" />
    </Button>
  );

  return (
    <>
      {/* ReasonTooltip also opens on tap and supplies the reason, so the Button adds no second tooltip. */}
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
      >
        {/* The API still allows it, and a database stopped here stays down until restarted. */}
        {engine && !offerForce ? (
          <Caution tone="destructive" size="md">
            {t("kill.databaseWarning", { engine })}
          </Caution>
        ) : null}
      </ConfirmDialog>
    </>
  );
}

const DATABASE_PROCESSES = [
  [/^(mysqld|mariadbd|mysqld_safe|mariadbd-safe)$/, "MariaDB / MySQL"],
  [/^mongod$/, "MongoDB"],
  [/^(postgres|postmaster)$/, "PostgreSQL"],
];

function databaseEngine(command) {
  const name = shortCommand(command).replace(/:$/, "");
  return DATABASE_PROCESSES.find(([pattern]) => pattern.test(name))?.[1] ?? null;
}

// The full command line can be hundreds of characters; keep the part that identifies it.
function shortCommand(command) {
  const first = String(command ?? "").trim().split(/\s+/)[0] || "";
  const name = first.split("/").pop();
  return name || String(command ?? "");
}
