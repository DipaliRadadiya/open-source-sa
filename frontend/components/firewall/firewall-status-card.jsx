"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  ArrowDownToLine,
  ArrowUpFromLine,
  ShieldCheck,
  ShieldOff,
  TriangleAlert,
} from "lucide-react";
import { toggleFirewall } from "@/lib/api/firewall";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Card, CardContent } from "@/components/ui/card";
import { apiMessage } from "@/lib/api/error-message";

/**
 * On or off, and what that actually means.
 *
 * Both directions get confirmed, for opposite reasons:
 *
 *   - **Turning it ON** changes the default for everything not listed to
 *     "blocked". The API seeds SSH and the panel's own ports first so the person
 *     clicking cannot lock themselves out, but they should be told that before
 *     they click, not discover it afterwards.
 *   - **Turning it OFF** stops enforcing every rule on the page. People expect
 *     "off" to also mean "lost my rules", so the dialog says they're kept —
 *     otherwise the safe action feels destructive and gets avoided.
 */
export function FirewallStatusCard({ enabled, policy, ruleCount, canManage }) {
  const t = useTranslations("firewall");
  const router = useRouter();
  const [confirming, setConfirming] = useState(null);
  const [pending, setPending] = useState(false);

  async function apply(next) {
    setPending(true);
    try {
      await toggleFirewall(next);
      toast.success(next ? t("status.turnedOn") : t("status.turnedOff"));
      setConfirming(null);
      router.refresh();
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
          // Off is not a neutral state on this page: the rules below are inert.
          // The card says so in colour before anyone reads a word of it.
          enabled ? "border-success/30 bg-success/5" : "border-warning/40 bg-warning/5"
        }
      >
        <CardContent className="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
          <div className="flex items-start gap-3">
            <span
              className={`flex size-10 shrink-0 items-center justify-center rounded-full ${
                enabled ? "bg-success/15 text-success" : "bg-warning/15 text-warning"
              }`}
            >
              {enabled ? <ShieldCheck className="size-5" /> : <ShieldOff className="size-5" />}
            </span>
            <div className="space-y-1">
              <p className="text-base font-semibold">
                {enabled ? t("status.onTitle") : t("status.offTitle")}
              </p>
              <p className="text-sm text-muted-foreground">
                {enabled ? t("status.onBody") : t("status.offBody", { count: ruleCount })}
              </p>
              {/* Labelled once rather than twice. Both pills used to end in "by
                  default", which pushed the one word that differs — blocked vs
                  allowed — into the middle of a sentence set in 12px. The label
                  carries "by default" for the pair, so each pill is left with a
                  direction and an answer. */}
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

          <ReasonTooltip reason={canManage ? null : t("disabled.noPermission")}>
            {/* Destructive when it is the off switch: this stops enforcing every
                rule on the page and puts the server back on the open internet.
                As a plain outline button it was indistinguishable from "Add
                rule" — and its own confirm dialog already opens warning-toned,
                so the button was the only step in the flow saying nothing. */}
            <Button
              variant={enabled ? "destructive" : "default"}
              disabled={!canManage || pending}
              onClick={() => setConfirming(enabled ? "off" : "on")}
            >
              {enabled ? <ShieldOff className="size-4" /> : <ShieldCheck className="size-4" />}
              {enabled ? t("status.turnOff") : t("status.turnOn")}
            </Button>
          </ReasonTooltip>
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

      <ConfirmDialog
        open={confirming === "off"}
        onOpenChange={(open) => !pending && setConfirming(open ? "off" : null)}
        icon={TriangleAlert}
        tone="warning"
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

/**
 * One direction and its default, coloured by what that default actually means.
 *
 * The label is dimmed with opacity rather than `text-muted-foreground`, so it
 * stays a shade of the badge's own colour instead of dropping to grey inside a
 * tinted pill.
 */
function PolicyBadge({ icon: Icon, label, value, tone }) {
  return (
    <Badge variant={tone} className="gap-1.5 font-normal">
      <Icon className="size-3 opacity-70" />
      <span className="opacity-70">{label}</span>
      <span className="font-medium">{value}</span>
    </Badge>
  );
}

/**
 * Blocking by default is the whole point of switching a firewall on — and
 * allowing by default is the one value on this card that contradicts the two
 * sentences above it. The card claims "your server is protected" on the
 * strength of the firewall being enabled, which an enabled-but-open firewall
 * satisfies; this badge is currently the only thing that can say otherwise.
 */
function incomingTone(value) {
  if (value === "deny") return "success";
  if (value === "allow") return "warning";
  return "secondary";
}

/**
 * Outgoing-allow is what every ordinary server does, so it is stated in the
 * same confident green rather than hedged in grey. Outgoing-deny is a
 * deliberate hardening choice — unusual, but not a fault, so it is neither
 * praised nor flagged.
 */
function outgoingTone(value) {
  if (value === "allow") return "success";
  return "secondary";
}

// "deny"/"allow" are UFW's words, not everyone's. Anything unexpected is shown
// verbatim rather than mistranslated into a promise the firewall isn't making.
function policyWord(t, value) {
  if (value === "deny") return t("status.policyDeny");
  if (value === "allow") return t("status.policyAllow");
  return value;
}
