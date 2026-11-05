"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ShieldCheck, TriangleAlert } from "lucide-react";
import { useRefresh } from "@/hooks/use-refresh";
import { toggleFirewall } from "@/lib/api/firewall";
import { apiMessage } from "@/lib/api/error-message";
import { Switch } from "@/components/ui/switch";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

/**
 * The dashboard's firewall switch (Krishna, 5 Nov: a quick action on the tile). Both directions
 * go through the Firewall page's own confirmations: ON blocks anything unlisted (the API adds
 * SSH and the panel's ports first), OFF stops every rule but keeps them.
 */
export function FirewallQuickSwitch({ enabled, canManage, label }) {
  const t = useTranslations("firewall");
  const { refreshAndWait } = useRefresh();
  const [confirming, setConfirming] = useState(null);
  const [pending, setPending] = useState(false);
  const unknown = enabled === null || enabled === undefined;

  async function apply(next) {
    setPending(true);
    try {
      await toggleFirewall(next);
      await refreshAndWait();
      toast.success(next ? t("status.turnedOn") : t("status.turnedOff"));
      setConfirming(null);
    } catch (error) {
      toast.error(apiMessage(error, t("status.toggleFailed")));
    } finally {
      setPending(false);
    }
  }

  const reason = !canManage ? t("disabled.noPermission") : unknown ? t("status.unknownTitle") : null;

  return (
    <>
      <ReasonTooltip reason={reason}>
        <Switch
          checked={Boolean(enabled)}
          disabled={Boolean(reason) || pending}
          onCheckedChange={(next) => setConfirming(next ? "on" : "off")}
          aria-label={label}
        />
      </ReasonTooltip>

      <ConfirmDialog
        open={confirming === "on"}
        onOpenChange={(open) => !pending && setConfirming(open ? "on" : null)}
        icon={ShieldCheck}
        title={t("confirmOn.title")}
        description={t("confirmOn.description")}
        cancelLabel={t("common.cancel")}
        confirmLabel={t("status.turnOn")}
        pending={pending}
        onConfirm={() => apply(true)}
      />
      <ConfirmDialog
        open={confirming === "off"}
        onOpenChange={(open) => !pending && setConfirming(open ? "off" : null)}
        icon={TriangleAlert}
        tone="warning"
        confirmVariant="destructive"
        title={t("confirmOff.title")}
        description={t("confirmOff.description")}
        cancelLabel={t("common.cancel")}
        confirmLabel={t("status.turnOff")}
        pending={pending}
        onConfirm={() => apply(false)}
      />
    </>
  );
}
