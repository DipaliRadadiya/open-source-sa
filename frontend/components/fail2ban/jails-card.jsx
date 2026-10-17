import { useId, useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { jailLabel } from "@/lib/fail2ban/jail-label";
import { toast } from "sonner";
import { ShieldAlert, TriangleAlert, Info, Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { updateFail2ban } from "@/lib/api/fail2ban";
import { ensureIgnored, settingsPayload } from "@/lib/fail2ban/settings-payload";
import { PendingSwitch } from "@/components/ui/pending-switch";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  Card,
  CardContent,
  CardDescription,
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

// Enabling a risky jail asks first, every attempt: the API's `your_ip` is the panel
// host, not the browser, so it cannot prove the user is safe.
export function JailsCard({ jails, settings, yourIp, ignoreIps = [], canManage, asked, onAskedChange }) {
  const t = useTranslations("fail2ban");
  const { refreshAndWait } = useRefresh();
  const [pending, setPending] = useState(null);
  // Optimistic overrides keyed to the server value they were based on; owned by
  // ProtectionSection because the ban list reads them too.
  const setAsked = onAskedChange;
  const [guarding, setGuarding] = useState(null);
  // Which guard button is in flight; both act on the same jail.
  const [guardAction, setGuardAction] = useState(null);
  const [explaining, setExplaining] = useState(false);
  const explainerId = useId();

  // Pre-filled, never trusted. Derived until typed in, because the browser IP
  // arrives after the first render.
  const [typedIp, setIgnoreIp] = useState(null);
  const ignoreIp = typedIp ?? yourIp ?? "";

  const shown = (jail) => {
    const override = asked[jail.name];
    return override && override.from === jail.enabled ? override.value : jail.enabled;
  };

  async function apply(jail, enabled, { addIp = null, acknowledged = false } = {}) {
    setPending(jail.name);
    // Read the live server value, not the `jail` passed in: the guard dialog's
    // copy can be stale, and a stale base would retire the override at once.
    const server = (jails.find((entry) => entry.name === jail.name) ?? jail).enabled;
    setAsked((current) => ({ ...current, [jail.name]: { value: enabled, from: server } }));
    try {
      // Before the jail: the address is safe the moment the jail starts.
      if (addIp && !ignoreIps.includes(addIp)) await ensureIgnored(addIp);
      await updateFail2ban({
        // Required on every call: the endpoint rewrites the config as a unit.
        ...settingsPayload(settings),
        // The API leaves omitted jails unchanged.
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
      // Revert the switch: it must not show a refused state.
      setAsked((current) => {
        const next = { ...current };
        delete next[jail.name];
        return next;
      });
      // The lockout refusal can arrive as a bare 422 without an `errors` bag;
      // a 422 on switching a risky jail ON is that refusal.
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

  // The guard's two answers; `action` names the button that spins.
  function answerGuard(action, options) {
    if (!guarding) return;
    setGuardAction(action);
    apply(guarding.jail, guarding.enabled, options).finally(() => setGuardAction(null));
  }

  function toggle(jail, enabled) {
    // Always ask before enabling a risky jail: whether the user's address is
    // ignored cannot be known here.
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
          <CardTitle className="col-start-1 row-start-1 min-w-0 break-words text-base font-semibold">
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
          {/* The description gets the whole row, not the narrow space beside the help link. */}
          <CardDescription className="col-span-2 row-start-2">{t("jails.description")}</CardDescription>
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
                    // Highlight only actual failed attempts reported by the jail.
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
                    {/* What this jail does, in one line: "SSH" and "Repeat offenders" alone
                        did not say (Krishna, 13 Oct). Jails without a sentence show none. */}
                    {t.has(`jails.about.${jail.name}`) ? (
                      <p className="mt-1 text-sm leading-relaxed text-muted-foreground">{t(`jails.about.${jail.name}`)}</p>
                    ) : null}
                    <span className="mt-1 block break-all font-mono text-xs text-muted-foreground">{jail.name}</span>
                  </div>

                  <div className="col-start-2 row-start-1 flex justify-end">
                    <ReasonTooltip reason={canManage ? null : t("disabled.noPermission")}>
                      <PendingSwitch
                        checked={shown(jail)}
                        pending={pending === jail.name}
                        disabled={!canManage}
                        onCheckedChange={(next) => toggle(jail, next)}
                        aria-label={t("jails.toggle", { name: jailLabel(t, jail) })}
                      />
                    </ReasonTooltip>
                  </div>

                </div>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      {/* Not dismissible mid-write: closing would read as "cancelled". */}
      <Dialog
        open={guarding !== null}
        onOpenChange={(open) => !open && pending === null && setGuarding(null)}
      >
        {/* Matches FormModal's width. */}
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

          {/* Editable: only the user knows the address they connect from. */}
          <div className="space-y-2 py-2">
            <Label htmlFor="lockout-ip" hint={t("lockout.ipLabelHint")}>{t("lockout.ipLabel")}</Label>
            <Input
              id="lockout-ip"
              value={ignoreIp}
              onChange={(event) => setIgnoreIp(event.target.value)}
              placeholder={t("lockout.ipPlaceholder")}
              autoComplete="off"
              spellCheck={false}
              // Locked in flight: the address was already sent.
              disabled={pending !== null}
              className="font-mono text-xs"
            />
            <p className="text-xs text-muted-foreground">{t("lockout.ipHint")}</p>
          </div>

          <DialogFooter>
            {/* Deliberate order: the risky choice is the quiet one. */}
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

// By urgency: attack in progress, active bans, quiet. A disabled jail says so.
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

  // Configuration labels never claim a stopped service is watching.
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
