"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2, ShieldCheck, Sparkles } from "lucide-react";
import { updateFail2ban } from "@/lib/api/fail2ban";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Card, CardContent } from "@/components/ui/card";
import { settingsPayload } from "@/lib/fail2ban/settings-payload";
import { apiMessage } from "@/lib/api/error-message";

/**
 * One click to a protected server.
 *
 * A fresh fail2ban has every jail switched off — deliberately, so it doesn't
 * start banning the moment it appears. But that leaves the user with a list of
 * unfamiliar names and no idea which matter, and the one thing they must do
 * first (put their own address on the never-ban list) is on a different tab.
 * Almost nobody does it in the right order, and the ones who don't lock
 * themselves out.
 *
 * So this does the whole thing as a single action: add your address, then enable
 * the jails that exist. Same request, so it cannot half-apply and leave the
 * dangerous middle state — jails on, your address not yet exempt.
 *
 * Shown only while nothing is enabled. Once the server is protected this is
 * noise, and the jail switches are the right control for tuning.
 */
export function RecommendedSetup({ jails, settings, yourIp, ignoreIps = [], canManage }) {
  const t = useTranslations("fail2ban");
  const router = useRouter();
  const [pending, setPending] = useState(false);

  const anyEnabled = jails.some((jail) => jail.enabled);
  if (anyEnabled || jails.length === 0) return null;

  const willIgnoreMe = Boolean(yourIp) && !ignoreIps.includes(yourIp);

  async function apply() {
    setPending(true);
    try {
      await updateFail2ban({
        // The three numbers go on EVERY call: this endpoint rewrites the config
        // file as a unit and rejects a payload without them. Omitting them is
        // what made the first version fail with "the bantime field is required".
        ...settingsPayload(settings, ignoreIps),
        // Every jail the server actually has. Enabling a jail that isn't there
        // would be inventing config; the API decides what exists, not us.
        jails: Object.fromEntries(jails.map((jail) => [jail.name, true])),
        // Sent in the same call as the jails, so there is no window where the
        // SSH jail is live and the operator's own address is still bannable.
        ...(willIgnoreMe ? { ignore_ips: [...ignoreIps, yourIp] } : null),
        // The lockout check is satisfied by the ignore_ips above; this tells the
        // API we know what we're doing when it can't see that yet.
        acknowledged: true,
      });
      toast.success(t("recommended.done"));
      router.refresh();
    } catch (error) {
      const data = error.response?.data;
      toast.error(
        [apiMessage(error, t("recommended.failed")), data?.reference].filter(Boolean).join(" · "),
      );
    } finally {
      setPending(false);
    }
  }

  return (
    <Card className="border-primary/25 bg-[color-mix(in_oklab,var(--primary)_5%,var(--card))]">
      <CardContent className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-start gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
            <Sparkles className="size-5" />
          </span>
          <div className="space-y-1">
            <p className="text-base font-semibold">{t("recommended.title")}</p>
            {/* Says exactly what the click will do, including the part the user
                would otherwise have to remember on their own. */}
            <p className="text-sm text-muted-foreground">
              {willIgnoreMe
                ? t("recommended.bodyWithIp", { count: jails.length, ip: yourIp })
                : t("recommended.body", { count: jails.length })}
            </p>
          </div>
        </div>

        <ReasonTooltip reason={canManage ? null : t("disabled.noPermission")}>
          <Button disabled={!canManage || pending} onClick={apply}>
            {pending ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <ShieldCheck className="size-4" />
            )}
            {t("recommended.action")}
          </Button>
        </ReasonTooltip>
      </CardContent>
    </Card>
  );
}
