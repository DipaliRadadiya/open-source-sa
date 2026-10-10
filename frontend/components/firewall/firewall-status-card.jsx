"use client";

import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  ArrowDownToLine,
  ArrowUpFromLine,
  ListChecks,
  ShieldAlert,
  ShieldCheck,
  ShieldQuestion,
  ShieldOff,
  TriangleAlert,
} from "lucide-react";
import { toggleFirewall } from "@/lib/api/firewall";
import { firewallState } from "@/lib/firewall/state";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Card, CardContent } from "@/components/ui/card";
import { cn } from "@/lib/utils";
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
      {/* The headline with its action beside it, then the facts behind it. */}
      <Card className={cn("gap-0 py-0", safeLooking ? undefined : "border-warning/40")}>
        {/* The button sits beside the text, and under it on a phone, lined up with it. */}
        <CardContent className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-3 py-4 sm:grid-cols-[auto_minmax(0,1fr)_auto]">
          <span
            className={cn(
              "flex size-10 shrink-0 items-center justify-center rounded-xl",
              safeLooking ? "bg-success/10 text-success" : "bg-warning/15 text-warning",
            )}
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
          <div className="min-w-0 space-y-1">
            <p className="text-base font-semibold">{t(`status.${state}Title`)}</p>
            <p className="text-sm text-muted-foreground">
              {state === "off" ? t("status.offBody", { count: ruleCount }) : t(`status.${state}Body`)}
            </p>
            {state === "unknown" && reference ? (
              <p className="font-mono text-xs text-muted-foreground">{t("status.reference", { reference })}</p>
            ) : null}
          </div>
          {state === "unknown" ? null : (
            <div className="col-start-2 flex flex-wrap gap-2 sm:col-start-auto">
              {/* The repair leads; the off switch stays beside it. */}
              {state === "exposed" ? (
                <ReasonTooltip reason={canManage ? null : t("disabled.noPermission")}>
                  <Button disabled={!canManage || pending} onClick={() => setConfirming("secure")}>
                    <ShieldCheck className="size-4" />
                    {t("status.secureNow")}
                  </Button>
                </ReasonTooltip>
              ) : null}
              <ReasonTooltip reason={canManage ? null : t("disabled.noPermission")}>
                {/* Neutral when it is the off switch: the dangerous action is not the loudest on
                    the page. The confirm dialog carries the warning and the red button. */}
                <Button
                  variant={enabled ? "neutral" : "default"}
                  disabled={!canManage || pending}
                  onClick={() => setConfirming(enabled ? "off" : "on")}
                >
                  {enabled ? <ShieldOff className="size-4" /> : <ShieldCheck className="size-4" />}
                  {enabled ? t("status.turnOff") : t("status.turnOn")}
                </Button>
              </ReasonTooltip>
            </div>
          )}
        </CardContent>

        {/* "by default" is carried by the label row, so each cell shows only its value. */}
        {enabled && policy?.incoming ? (
          // Rows on a phone: three columns there split "Заблокировано" mid-word.
          <div className="grid divide-y border-t sm:grid-cols-3 sm:divide-x sm:divide-y-0">
            <Fact
              icon={ArrowDownToLine}
              label={t("status.incomingLabel")}
              value={policyWord(t, policy.incoming)}
              tone={incomingTone(policy.incoming)}
            />
            <Fact
              icon={ArrowUpFromLine}
              label={t("status.outgoingLabel")}
              value={policyWord(t, policy.outgoing)}
              tone={outgoingTone(policy.outgoing)}
            />
            <Fact icon={ListChecks} label={t("rules.title")} value={ruleCount} tone="muted" />
          </div>
        ) : null}

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

// One fact per cell: the label small and grey, the value in words. Colour only on the
// icon, and only when the value is good or worth a second look.
const FACT_TONE = {
  success: "bg-success/10 text-success",
  warning: "bg-warning/15 text-warning",
  muted: "bg-muted text-muted-foreground",
};

function Fact({ icon: Icon, label, value, tone }) {
  return (
    <div className="flex min-w-0 items-center gap-2.5 px-4 py-2.5 sm:px-5 sm:py-3">
      <span className={cn("flex size-7 shrink-0 items-center justify-center rounded-lg sm:size-8", FACT_TONE[tone] ?? FACT_TONE.muted)}>
        <Icon className="size-4" aria-hidden />
      </span>
      {/* Label and value side by side on a phone row, stacked in a column cell. */}
      <div className="flex min-w-0 flex-1 items-baseline justify-between gap-3 sm:block">
        <p className="text-xs text-muted-foreground">{label}</p>
        <p className="text-sm font-semibold tabular-nums">{value}</p>
      </div>
    </div>
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
