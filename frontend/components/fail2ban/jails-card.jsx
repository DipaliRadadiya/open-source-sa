import { useId, useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { jailLabel } from "@/lib/fail2ban/jail-label";
import { toast } from "sonner";
import { ShieldAlert, TriangleAlert, Info, Loader2, Lock } from "lucide-react";
import { cn } from "@/lib/utils";
import { updateFail2ban } from "@/lib/api/fail2ban";
import { ensureIgnored, settingsPayload } from "@/lib/fail2ban/settings-payload";
import { PendingSwitch } from "@/components/ui/pending-switch";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { apiMessage } from "@/lib/api/error-message";

// your_ip from the API is the panel host, not the browser, so always ask.
export function JailsCard({ jails, settings, yourIp, ignoreIps = [], canManage, asked, onAskedChange }) {
  const t = useTranslations("fail2ban");
  const { refreshAndWait } = useRefresh();
  const [pending, setPending] = useState(null);
  const setAsked = onAskedChange;
  const [guarding, setGuarding] = useState(null);
  const [guardAction, setGuardAction] = useState(null);
  const [explaining, setExplaining] = useState(false);
  const explainerId = useId();

  const [typedIp, setIgnoreIp] = useState(null);
  const ignoreIp = typedIp ?? yourIp ?? "";

  const shown = (jail) => {
    const override = asked[jail.name];
    return override && override.from === jail.enabled ? override.value : jail.enabled;
  };

  async function apply(jail, enabled, { addIp = null, acknowledged = false } = {}) {
    setPending(jail.name);
    // Use the live server value; the dialog's copy can be stale.
    const server = (jails.find((entry) => entry.name === jail.name) ?? jail).enabled;
    setAsked((current) => ({ ...current, [jail.name]: { value: enabled, from: server } }));
    try {
      if (addIp && !ignoreIps.includes(addIp)) await ensureIgnored(addIp);
      await updateFail2ban({
        ...settingsPayload(settings),
        jails: { [jail.name]: enabled },
        ...(acknowledged ? { acknowledged: true } : null),
      });
      await refreshAndWait();
      toast.success(
        enabled ? t("jails.enabled", { name: jailLabel(t, jail) }) : t("jails.disabled", { name: jailLabel(t, jail) }),
      );
      setGuarding(null);
    } catch (error) {
      const data = error.response?.data;
      setAsked((current) => {
        const next = { ...current };
        delete next[jail.name];
        return next;
      });
      // The lockout refusal can come as a bare 422 without errors.
      const refusedForLockout =
        Boolean(data?.errors?.["fail2ban.lockout_risk"]) ||
        (error.response?.status === 422 && enabled && jail.lockout_risk);
      if (refusedForLockout) {
        setGuarding({ jail, enabled, serverReason: data?.message ?? null });
      } else {
        toast.error(
          apiMessage(error, t("jails.failed")),
        );
      }
    } finally {
      setPending(null);
    }
  }

  function answerGuard(action, options) {
    if (!guarding) return;
    setGuardAction(action);
    apply(guarding.jail, guarding.enabled, options).finally(() => setGuardAction(null));
  }

  function toggle(jail, enabled) {
    if (enabled && jail.lockout_risk) {
      setGuarding({ jail, enabled });
      return;
    }
    apply(jail, enabled);
  }

  return (
    <>
      <Card>
        <CardHeader className="grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-1">
          <CardTitle icon={Lock} className="col-start-1 row-start-1 min-w-0 break-words font-semibold">
            {t("jails.title")}
          </CardTitle>
          <button
            type="button"
            onClick={() => setExplaining((open) => !open)}
            aria-expanded={explaining}
            aria-controls={explainerId}
            className="col-start-2 row-start-1 flex max-w-full items-center gap-1.5 rounded text-left text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
          >
            <Info className="size-3.5 shrink-0" />
            {t("jails.whatIs")}
          </button>
        </CardHeader>
        <CardContent className="space-y-3">
          <p
            id={explainerId}
            hidden={!explaining}
            className="rounded-lg bg-muted/60 p-3 text-sm leading-relaxed text-muted-foreground"
          >
            {t("jails.explainerBody")}
          </p>

          <div className={cn("grid gap-3", jails.length > 1 && "lg:grid-cols-2")}>
            {jails.map((jail) => (
              <div key={jail.name}>
                <div
                  className={cn(
                    "grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 rounded-lg border p-4",
                    jail.stats?.currently_failed > 0 && "border-warning/40 bg-warning/5",
                  )}
                >
                  <div className="col-start-1 row-start-1 min-w-0">
                    <div className="flex min-w-0 items-center gap-2">
                      <span className="min-w-0 break-words font-medium [overflow-wrap:anywhere]">{jailLabel(t, jail)}</span>
                      {jail.lockout_risk ? (
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <span tabIndex={0} className="shrink-0 rounded">
                              <ShieldAlert className="size-3.5 text-warning" />
                            </span>
                          </TooltipTrigger>
                          <TooltipContent className="max-w-60">
                            {t("jails.lockoutHint")}
                          </TooltipContent>
                        </Tooltip>
                      ) : null}
                      <JailState jail={jail} t={t} />
                    </div>
                    {t.has(`jails.about.${jail.name}`) ? (
                      <p className="mt-1 text-sm leading-relaxed text-muted-foreground">{t(`jails.about.${jail.name}`)}</p>
                    ) : null}
                    <span className="mt-1 block break-all font-mono text-xs text-muted-foreground">{jail.name}</span>
                  </div>

                  {canManage ? (
                    <div className="col-start-2 row-start-1 flex justify-end">
                      <PendingSwitch
                        checked={shown(jail)}
                        pending={pending === jail.name}
                        onCheckedChange={(next) => toggle(jail, next)}
                        aria-label={t("jails.toggle", { name: jailLabel(t, jail) })}
                      />
                    </div>
                  ) : null}

                </div>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      <Dialog
        open={guarding !== null}
        onOpenChange={(open) => !open && pending === null && setGuarding(null)}
      >
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <div className="flex items-center gap-3">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-warning/15 text-warning">
                <TriangleAlert className="size-5" />
              </span>
              <DialogTitle>{t("lockout.title")}</DialogTitle>
            </div>
            <DialogDescription className="pt-1">
              {guarding?.serverReason ?? t("lockout.description")}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-2 py-2">
            <Label htmlFor="lockout-ip" hint={t("lockout.ipLabelHint")}>{t("lockout.ipLabel")}</Label>
            <Input
              id="lockout-ip"
              value={ignoreIp}
              onChange={(event) => setIgnoreIp(event.target.value)}
              placeholder={t("lockout.ipPlaceholder")}
              autoComplete="off"
              spellCheck={false}
              disabled={pending !== null}
              className="font-mono text-xs"
            />
            <p className="text-xs text-muted-foreground">{t("lockout.ipHint")}</p>
          </div>

          <DialogFooter>
            <Button
              variant="ghost"
              disabled={pending !== null}
              onClick={() => answerGuard("anyway", { acknowledged: true })}
            >
              {guardAction === "anyway" ? <Loader2 className="size-4 animate-spin" /> : null}
              {t("lockout.anyway")}
            </Button>
            <Button
              disabled={pending !== null || !ignoreIp.trim()}
              onClick={() =>
                answerGuard("add", { addIp: ignoreIp.trim(), acknowledged: true })
              }
            >
              {guardAction === "add" ? <Loader2 className="size-4 animate-spin" /> : null}
              {t("lockout.addIp")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

function JailState({ jail, t }) {
  const failing = jail.stats?.currently_failed ?? 0;
  const blocked = jail.stats?.currently_banned ?? 0;

  let text = t("jails.stateQuiet");
  let tone = "text-muted-foreground";

  if (!jail.enabled) {
    text = t("jails.stateOff");
  } else if (failing > 0) {
    text = t("jails.stateFailing", { count: failing });
    tone = "text-warning font-medium";
  } else if (blocked > 0) {
    text = t("jails.stateBanned", { count: blocked });
    tone = "text-foreground";
  } else if (!jail.stats) {
    text = t("jails.stateUnknown");
  }

  const label = !jail.enabled
    ? t("jails.badgeOff")
    : failing > 0
      ? t("jails.badgeFailing", { count: failing })
      : blocked > 0
        ? t("jails.badgeBanned", { count: blocked })
        : t("jails.badgeEnabled");
  const variant = !jail.enabled || !jail.stats
    ? "muted"
    : failing > 0
      ? "warning"
      : "success";
  const line = (
    <Badge variant={variant} className="font-normal" aria-label={text}>
      {label}
    </Badge>
  );

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span tabIndex={0} className={cn("shrink-0 max-w-full rounded", tone)}>
          {line}
        </span>
      </TooltipTrigger>
      <TooltipContent className="flex-col items-start gap-1">
        <span>{text}</span>
        {jail.stats ? (
          <span>
            {t("jails.totalsTooltip", {
              failed: jail.stats.total_failed ?? "—",
              banned: jail.stats.total_banned ?? "—",
            })}
          </span>
        ) : null}
      </TooltipContent>
    </Tooltip>
  );
}
