import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2, ShieldCheck, Sparkles } from "lucide-react";
import { updateFail2ban } from "@/lib/api/fail2ban";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Card, CardContent } from "@/components/ui/card";
import { settingsPayload } from "@/lib/fail2ban/settings-payload";
import { apiMessage } from "@/lib/api/error-message";
import { useBrowserIpSettled } from "@/components/network/browser-ip";

// Ignores the user's IP and enables every jail in one request, so it can never
// leave jails on with the user bannable.
export function RecommendedSetup({ jails, settings, yourIp, ignoreIps = [], canManage }) {
  const t = useTranslations("fail2ban");
  const { refreshAndWait } = useRefresh();
  const [pending, setPending] = useState(false);
  const ipSettled = useBrowserIpSettled();

  const anyEnabled = jails.some((jail) => jail.enabled);
  if (anyEnabled || jails.length === 0) return null;

  const willIgnoreMe = Boolean(yourIp) && !ignoreIps.includes(yourIp);

  async function apply() {
    setPending(true);
    try {
      await updateFail2ban({
        // Required on every call: the endpoint rewrites the config as a unit.
        ...settingsPayload(settings, ignoreIps),
        // Only the jails the server reports.
        jails: Object.fromEntries(jails.map((jail) => [jail.name, true])),
        // Same call as the jails, so there is no window where the user is bannable.
        ...(willIgnoreMe ? { ignore_ips: [...ignoreIps, yourIp] } : null),
        // Only when the user's IP is known and covered above; otherwise the
        // API's lockout check runs against this request's address.
        acknowledged: Boolean(yourIp),
      });
      // Refresh first, so the toast never sits above stale "not protected" state.
      await refreshAndWait();
      toast.success(t("recommended.done"));
    } catch (error) {
      toast.error(
        apiMessage(error, t("recommended.failed")),
      );
    } finally {
      setPending(false);
    }
  }

  return (
    <Card className="border-primary/25 bg-[color-mix(in_oklab,var(--primary)_5%,var(--card))]">
      <CardContent className="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <div className="flex items-start gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
            <Sparkles className="size-5" />
          </span>
          <div className="space-y-1">
            <p className="text-base font-semibold">{t("recommended.title")}</p>
            <p className="text-sm text-muted-foreground">
              {willIgnoreMe
                ? t("recommended.bodyWithIp", { count: jails.length, ip: yourIp })
                : t("recommended.body", { count: jails.length })}
            </p>
          </div>
        </div>

        <ReasonTooltip reason={!canManage ? t("disabled.noPermission") : !ipSettled ? t("recommended.checkingIp") : null}>
          <Button disabled={!canManage || pending || !ipSettled} onClick={apply}>
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
