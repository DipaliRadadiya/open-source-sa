"use client";

import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  ArrowDownToLine,
  ArrowUpFromLine,
  ShieldAlert,
  ShieldCheck,
  ShieldQuestion,
  ShieldOff,
  TriangleAlert,
} from "lucide-react";
import { toggleFirewall } from "@/lib/api/firewall";
import { firewallState } from "@/lib/firewall/state";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Card, CardContent } from "@/components/ui/card";
import { apiMessage } from "@/lib/api/error-message";

// Both directions are confirmed: ON blocks anything unlisted (the API seeds SSH and
// panel ports first); OFF stops every rule but keeps them.
export function FirewallStatusCard({ enabled, reference = null, policy, ruleCount, canManage }) {
  const t = useTranslations("firewall");
  const { refreshAndWait } = useRefresh();
  const [confirming, setConfirming] = useState(null);
  const [pending, setPending] = useState(false);

  // off | on | exposed; see lib/firewall/state.js.
  const state = firewallState(enabled, policy);
  const safeLooking = state === "on";

  // `secure` re-runs the enable path, which seeds SSH and panel rules before changing
  // the policy, so there is no unprotected window.
  async function apply(next, { secured = false } = {}) {
    setPending(true);
    try {
      await toggleFirewall(next);
      await refreshAndWait();
      toast.success(
        secured ? t("status.secured") : next ? t("status.turnedOn") : t("status.turnedOff"),
      );
      setConfirming(null);
    } catch (error) {
      toast.error(
        apiMessage(error, t("status.toggleFailed")),
      );
    } finally {
      setPending(false);
    }
  }

  return (
    <>
      <Card
        className={
          // Both off and exposed are warning states: rules are inert, or everything
          // unlisted gets in.
          safeLooking ? "border-success/30 bg-success/5" : "border-warning/40 bg-warning/5"
        }
      >
        <CardContent className="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
          <div className="flex items-start gap-3">
            <span
              className={`flex size-10 shrink-0 items-center justify-center rounded-full ${
                safeLooking ? "bg-success/15 text-success" : "bg-warning/15 text-warning"
              }`}
            >
              {/* Not the "off" icon: this firewall IS running. */}
              {state === "unknown" ? (
                <ShieldQuestion className="size-5" />
              ) : state === "on" ? (
                <ShieldCheck className="size-5" />
              ) : state === "exposed" ? (
                <ShieldAlert className="size-5" />
              ) : (
                <ShieldOff className="size-5" />
              )}
            </span>
            <div className="space-y-1">
              <p className="text-base font-semibold">
                {t(`status.${state}Title`)}
              </p>
              <p className="text-sm text-muted-foreground">
                {state === "off"
                  ? t("status.offBody", { count: ruleCount })
                  : t(`status.${state}Body`)}
              </p>
              {state === "unknown" && reference ? (
                <p className="font-mono text-xs text-muted-foreground">{t("status.reference", { reference })}</p>
              ) : null}
              {/* "by default" is carried by the label, so each pill shows only direction and
                  value. */}
              {enabled && policy?.incoming ? (
                <div className="flex flex-wrap items-center gap-2 pt-1.5">
                  <span className="text-xs text-muted-foreground">
                    {t("status.defaultPolicy")}
                  </span>
                  <PolicyBadge
                    icon={ArrowDownToLine}
                    label={t("status.incomingLabel")}
                    value={policyWord(t, policy.incoming)}
                    tone={incomingTone(policy.incoming)}
                  />
                  <PolicyBadge
                    icon={ArrowUpFromLine}
                    label={t("status.outgoingLabel")}
                    value={policyWord(t, policy.outgoing)}
                    tone={outgoingTone(policy.outgoing)}
                  />
                </div>
              ) : null}
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            {/* The repair leads; the off switch stays beside it. */}
            {state === "exposed" ? (
              <ReasonTooltip reason={canManage ? null : t("disabled.noPermission")}>
                <Button
                  disabled={!canManage || pending}
                  onClick={() => setConfirming("secure")}
                >
                  <ShieldCheck className="size-4" />
                  {t("status.secureNow")}
                </Button>
              </ReasonTooltip>
            ) : null}

            {state === "unknown" ? null : (
            <ReasonTooltip reason={canManage ? null : t("disabled.noPermission")}>
              {/* Destructive when it is the off switch: it stops enforcing every rule, and the
                  confirm dialog is warning-toned too. */}
              <Button
                variant={enabled ? "destructive" : "default"}
                disabled={!canManage || pending}
                onClick={() => setConfirming(enabled ? "off" : "on")}
              >
                {enabled ? <ShieldOff className="size-4" /> : <ShieldCheck className="size-4" />}
                {enabled ? t("status.turnOff") : t("status.turnOn")}
              </Button>
            </ReasonTooltip>
            )}
          </div>
        </CardContent>
      </Card>

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

      {/* The same call seeds SSH and panel rules first, so the copy can promise access is kept. */}
      <ConfirmDialog
        open={confirming === "secure"}
        onOpenChange={(open) => !pending && setConfirming(open ? "secure" : null)}
        icon={ShieldCheck}
        title={t("confirmSecure.title")}
        description={t("confirmSecure.description")}
        cancelLabel={t("common.cancel")}
        confirmLabel={t("status.secureNow")}
        pending={pending}
        onConfirm={() => apply(true, { secured: true })}
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

// Text stays in the text colour (badge colours fail contrast on the tint).
function PolicyBadge({ icon: Icon, label, value, tone }) {
  return (
    <Badge variant={tone} className="gap-1.5 font-normal">
      <Icon className="size-3 opacity-70" />
      <span className="text-foreground/75">{label}</span>
      <span className="font-medium text-foreground">{value}</span>
    </Badge>
  );
}

// Allow-by-default is not flagged anywhere else, so this badge must flag it.
function incomingTone(value) {
  if (value === "deny") return "success";
  if (value === "allow") return "warning";
  return "muted";
}

// Outgoing-deny is deliberate hardening: neither praised nor flagged.
function outgoingTone(value) {
  if (value === "allow") return "success";
  return "muted";
}

// "deny"/"allow" are UFW's words. Unknown values are shown verbatim rather than
// mistranslated.
function policyWord(t, value) {
  if (value === "deny") return t("status.policyDeny");
  if (value === "allow") return t("status.policyAllow");
  return value;
}
