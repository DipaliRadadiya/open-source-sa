import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { CircleStop, TriangleAlert } from "lucide-react";
import { killProcess } from "@/lib/api/server-metrics";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Caution } from "@/components/ui/caution";
import { DENIED, ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { apiMessage } from "@/lib/api/error-message";

export function KillProcessButton({ process, canManage }) {
  const t = useTranslations("serverDashboard");
  const { refreshThen } = useRefresh();
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);
  const [offerForce, setOfferForce] = useState(false);
  const engine = databaseEngine(process.command);
  const refusal = !canManage
    ? DENIED
    : process.stoppable === false
      ? process.reason || t("kill.refused")
      : null;

  async function run(signal) {
    setPending(true);
    try {
      await killProcess(process.pid, signal);
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
        refreshThen(() => {
          toast.info(t("kill.alreadyGone"));
          setConfirming(false);
          setPending(false);
        });
        return;
      }

      if (status === 422) {
        toast.error(apiMessage(error, t("kill.refused")));
        setConfirming(false);
        setPending(false);
        return;
      }

      toast.error(
        apiMessage(error, t("kill.failed")),
      );
      if (signal === "TERM") setOfferForce(true);
    }
    setPending(false);
  }

  const stopButton = (
    <Button
      variant="ghost"
      size="icon"
      className="size-8 text-destructive hover:bg-destructive/10 hover:text-destructive"
      disabled={Boolean(refusal)}
      onClick={() => {
        setOfferForce(false);
        setConfirming(true);
      }}
      aria-label={t("kill.action")}
    >
      <CircleStop className="size-4" />
    </Button>
  );

  return (
    <>
      {refusal ? (
        <ReasonTooltip reason={refusal}>{stopButton}</ReasonTooltip>
      ) : (
        <Tooltip>
          <TooltipTrigger asChild>{stopButton}</TooltipTrigger>
          <TooltipContent>{t("kill.action")}</TooltipContent>
        </Tooltip>
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

function shortCommand(command) {
  const first = String(command ?? "").trim().split(/\s+/)[0] || "";
  const name = first.split("/").pop();
  return name || String(command ?? "");
}
