"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { TriangleAlert, Lock } from "lucide-react";
import { runServiceAction } from "@/lib/api/services";
import { showActionError } from "@/components/services/service-toast";
import { Switch } from "@/components/ui/switch";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { apiMessage } from "@/lib/api/error-message";

/**
 * "Start on boot" — enable/disable, driven by the service's own `actions` so a
 * protected unit (the panel's own web server) can't be switched off.
 *
 * Turning it ON runs immediately; turning it OFF asks first. The asymmetry is
 * deliberate: the cost is asymmetric. Forgetting you disabled a database is
 * something you discover at the next reboot, which is the worst time to find it.
 */
export function ServiceBootSwitch({ service, canManage, onBusyChange }) {
  const t = useTranslations("services");
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [confirming, setConfirming] = useState(false);

  const allowed = service.actions ?? [];
  const canToggle =
    canManage && allowed.includes(service.enabled ? "disable" : "enable");

  async function run(action) {
    setBusy(true);
    onBusyChange?.(action);
    try {
      await runServiceAction(service.key, action);
      toast.success(t(`toast.${action}`, { name: service.label }));
      router.refresh();
    } catch (error) {
      const data = error.response?.data;
      showActionError({
        title: t(`error.${action}`, { name: service.label }),
        message: apiMessage(error),
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

  // Nothing to enable: an installing or failed-to-install row has no unit yet,
  // so `actions` is empty and the switch would render permanently disabled —
  // the pale half-lit control this component already refuses to draw below. A
  // dash, matching the empty usage figures on the same row.
  if (service.state && service.state !== "installed") {
    return <span className="text-muted-foreground">—</span>;
  }

  // A service that can never be switched off shouldn't be represented by a
  // switch. Disabled-and-on renders as a pale half-lit control that reads as a
  // glitch — "is that on? loading?" — so the fact is stated in words instead.
  if (service.protected) {
    return (
      <Tooltip>
        <TooltipTrigger asChild>
          <span
            tabIndex={0}
            className="inline-flex items-center gap-1.5 whitespace-nowrap rounded text-sm text-muted-foreground"
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
    <Switch
      checked={service.enabled}
      disabled={busy || !canToggle}
      onCheckedChange={(next) => (next ? run("enable") : setConfirming(true))}
      aria-label={t("columns.boot")}
    />
  );

  return (
    <>
      {canToggle ? (
        control
      ) : (
        // A locked switch with no explanation reads as a bug.
        <Tooltip>
          <TooltipTrigger asChild>
            <span tabIndex={0} className="inline-flex">
              {control}
            </span>
          </TooltipTrigger>
          <TooltipContent>{t("noPermission")}</TooltipContent>
        </Tooltip>
      )}

      <ConfirmDialog
        open={confirming}
        onOpenChange={setConfirming}
        icon={TriangleAlert}
        tone="warning"
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
