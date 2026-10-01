import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { TriangleAlert, Lock } from "lucide-react";
import { runServiceAction } from "@/lib/api/services";
import { showActionError } from "@/components/services/service-toast";
import { PendingSwitch } from "@/components/ui/pending-switch";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { apiMessage } from "@/lib/api/error-message";

// Driven by the service's `actions`, so a protected unit cannot be switched off.
// OFF asks first: a disabled service is only noticed at the next reboot.
export function ServiceBootSwitch({ service, canManage, onBusyChange }) {
  const t = useTranslations("services");
  const { refreshAndWait } = useRefresh();
  const [busy, setBusy] = useState(false);
  const [confirming, setConfirming] = useState(false);
  // The requested value, until the server agrees with it.
  const [asked, setAsked] = useState(null);

  const allowed = service.actions ?? [];
  const canToggle =
    canManage && allowed.includes(service.enabled ? "disable" : "enable");

  // Optimistic: `service.enabled` only changes once `router.refresh()` lands.
  const shown =
    asked !== null && asked !== service.enabled ? asked : service.enabled;

  async function run(action) {
    setBusy(true);
    setAsked(action === "enable");
    onBusyChange?.(action);
    try {
      await runServiceAction(service.key, action);
      await refreshAndWait();
      toast.success(t(`toast.${action}`, { name: service.label }));
    } catch (error) {
  // Revert: the change did not happen.
      setAsked(null);
      const data = error.response?.data;
      showActionError({
        // No answer at all (connection dropped) is not "left as it was": the
        // server may have done it. The list re-reads every 3 s and shows which.
        title: error.response
          ? t(`error.${action}`, { name: service.label })
          : t("error.noAnswer", { name: service.label }),
        // The title already says there was no answer.
        message: error.response ? apiMessage(error, undefined, { reference: false }) : undefined,
        reference: data?.reference,
        copyLabel: t('copyReference'),
        copiedLabel: t('copiedReference'),
        retryLabel: t('retry'),
        onRetry: () => run(action),
      });
    } finally {
      setBusy(false);
      onBusyChange?.(null);
      setConfirming(false);
    }
  }

  // Installing or failed-install rows have no unit yet (`actions` is empty),
  // so a dash is shown, matching the empty usage figures on the row.
  if (service.state && service.state !== "installed") {
    return <span className="text-muted-foreground">—</span>;
  }

  // A service that can never be switched off is stated in words; a
  // disabled-and-on switch reads as a glitch.
  if (service.protected) {
    return (
      <Tooltip>
        <TooltipTrigger asChild>
          <span
            tabIndex={0}
            className="inline-flex items-center gap-1.5 rounded text-sm whitespace-normal text-muted-foreground"
          >
            <Lock className="size-3.5" />
            {t("alwaysOn")}
          </span>
        </TooltipTrigger>
        <TooltipContent className="max-w-56">{t("protectedHint")}</TooltipContent>
      </Tooltip>
    );
  }

  const control = (
    <PendingSwitch
      checked={shown}
      pending={busy}
      disabled={!canToggle}
      onCheckedChange={(next) => (next ? run("enable") : setConfirming(true))}
      aria-label={t("columns.boot")}
    />
  );

  return (
    <>
      {/* ReasonTooltip, not Tooltip: a plain tooltip never opens on a tap. */}
      <ReasonTooltip reason={canToggle ? null : t("noPermission")}>{control}</ReasonTooltip>

      <ConfirmDialog
        open={confirming}
        onOpenChange={setConfirming}
        icon={TriangleAlert}
        tone="warning"
        confirmVariant="destructive"
        title={t("confirm.disable.title", { name: service.label })}
        description={t("confirm.disable.description")}
        cancelLabel={t("confirm.cancel")}
        confirmLabel={t("actions.disable")}
        pending={busy}
        onConfirm={() => run("disable")}
      />
    </>
  );
}
