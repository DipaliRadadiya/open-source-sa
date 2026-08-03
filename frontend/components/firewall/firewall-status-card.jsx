"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ShieldCheck, ShieldOff, TriangleAlert } from "lucide-react";
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
      const data = error.response?.data;
      toast.error(
        [apiMessage(error, t("status.toggleFailed")), data?.reference].filter(Boolean).join(" · "),
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
        <CardContent className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
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
              {enabled && policy?.incoming ? (
                <div className="flex flex-wrap items-center gap-1.5 pt-1">
                  <Badge variant="outline" className="font-normal">
                    {t("status.incoming", { policy: policyWord(t, policy.incoming) })}
                  </Badge>
                  <Badge variant="outline" className="font-normal">
                    {t("status.outgoing", { policy: policyWord(t, policy.outgoing) })}
                  </Badge>
                </div>
              ) : null}
            </div>
          </div>

          <ReasonTooltip reason={canManage ? null : t("disabled.noPermission")}>
            <Button
              variant={enabled ? "outline" : "default"}
              disabled={!canManage || pending}
              onClick={() => setConfirming(enabled ? "off" : "on")}
            >
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

// "deny"/"allow" are UFW's words, not everyone's. Anything unexpected is shown
// verbatim rather than mistranslated into a promise the firewall isn't making.
function policyWord(t, value) {
  if (value === "deny") return t("status.policyDeny");
  if (value === "allow") return t("status.policyAllow");
  return value;
}
