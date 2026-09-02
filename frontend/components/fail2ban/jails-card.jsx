import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ShieldAlert, TriangleAlert, Info, Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { updateFail2ban } from "@/lib/api/fail2ban";
import { settingsPayload } from "@/lib/fail2ban/settings-payload";
import { PendingSwitch } from "@/components/ui/pending-switch";
import { Button } from "@/components/ui/button";
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

/**
 * The jails, and the guard that stops you locking yourself out.
 *
 * Enabling the SSH jail when your own address isn't on the ignore list can end
 * with fail2ban banning you from your own server. The API refuses that unless
 * you're ignored or you explicitly acknowledge — and rather than forwarding the
 * refusal as an error, this asks first and makes **adding an address the primary
 * action**. The safe path should be the easy one.
 *
 * The guard fires on every attempt to switch a risky jail on, and again if the
 * server refuses anyway. It used to be skipped whenever `your_ip` appeared in
 * the ignore list — but `your_ip` is the address the API saw, and this page is
 * rendered on the server, so that address is the panel's own host. The result
 * was a jail you could switch off and never switch back on: no dialog, no way
 * to add an address, no way to acknowledge, just a red toast.
 */
export function JailsCard({ jails, settings, yourIp, ignoreIps = [], canManage, asked, onAskedChange }) {
  const t = useTranslations("fail2ban");
  const router = useRouter();
  const [pending, setPending] = useState(null);
  // What the user asked for, held until the server catches up. Stored WITH the
  // value it was based on, so once the server moves off that value the answer
  // has landed and the override retires itself. Without this the switch did not
  // move on click and still read "on" after a successful off, because `checked`
  // was the server value and the refresh had not come back yet.
  // Owned by ProtectionSection, because the ban list has to read it too.
  const setAsked = onAskedChange;
  const [guarding, setGuarding] = useState(null);
  // Which of the guard's two answers is in flight. `pending` only knows a jail
  // is busy, and both buttons here act on the same jail — without this neither
  // could show a spinner, so the dialog sat inert through a config rewrite and
  // a fail2ban reload, which takes seconds.
  const [guardAction, setGuardAction] = useState(null);
  const [explaining, setExplaining] = useState(false);

  // Pre-filled, never trusted. `your_ip` is whatever address the API saw make
  // the request — and this page is server-rendered, so on a real install that
  // is the panel's own server, not the browser. Checking it against the ignore
  // list used to "prove" you were safe and skip the warning entirely, which is
  // how the SSH jail became a switch you could turn off and never back on.
  const [ignoreIp, setIgnoreIp] = useState(yourIp ?? "");

  const shown = (jail) => {
    const override = asked[jail.name];
    return override && override.from === jail.enabled ? override.value : jail.enabled;
  };

  async function apply(jail, enabled, { addIp = null, acknowledged = false } = {}) {
    setPending(jail.name);
    // Read the server value NOW, not off the `jail` the caller is holding: the
    // guard dialog hands back the jail as it was when it opened, which can be
    // two refreshes old. The override retires when the server moves off the
    // value it was based on, so a stale base retires it instantly and the
    // switch snaps back to a state the user just changed — it did exactly that
    // after a successful "turn it on", while the toast said it had worked.
    const server = (jails.find((entry) => entry.name === jail.name) ?? jail).enabled;
    setAsked((current) => ({ ...current, [jail.name]: { value: enabled, from: server } }));
    try {
      await updateFail2ban({
        // The settings numbers ride along on every call — this endpoint rewrites
        // the config as a unit and rejects a payload without them. Without this
        // a jail toggle fails validation before it ever reaches fail2ban.
        ...settingsPayload(settings, ignoreIps),
        // Only this jail is sent: the API keeps omitted jails as they are, so a
        // single toggle can't disturb the others.
        jails: { [jail.name]: enabled },
        ...(addIp && !ignoreIps.includes(addIp) ? { ignore_ips: [...ignoreIps, addIp] } : null),
        ...(acknowledged ? { acknowledged: true } : null),
      });
      toast.success(
        enabled ? t("jails.enabled", { name: jail.label }) : t("jails.disabled", { name: jail.label }),
      );
      setGuarding(null);
      router.refresh();
    } catch (error) {
      const data = error.response?.data;
      // Put the switch back where it was: it must not sit showing a state the
      // server refused.
      setAsked((current) => {
        const next = { ...current };
        delete next[jail.name];
        return next;
      });
      // The server's own lockout refusal. It arrives as a bare 422 with a
      // `message` and no `errors` bag, so keying off `errors["fail2ban.lockout_risk"]`
      // alone silently missed every real refusal and left a dead-end toast:
      // switching a risky jail ON is the only thing here that earns a 422, so
      // that combination IS the refusal.
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

  // The guard's two ways out, both of which write the config and reload
  // fail2ban. Named so the button that was pressed is the one that spins.
  function answerGuard(action, options) {
    if (!guarding) return;
    setGuardAction(action);
    apply(guarding.jail, guarding.enabled, options).finally(() => setGuardAction(null));
  }

  function toggle(jail, enabled) {
    // Ask before the API has to refuse: turning ON a risky jail is the move
    // that locks people out. Asked every time rather than only when we think
    // your address isn't ignored — we cannot tell from here (see `ignoreIp`),
    // and a warning shown once too often costs a click, while one skipped
    // costs the server.
    if (enabled && jail.lockout_risk) {
      setGuarding({ jail, enabled });
      return;
    }
    apply(jail, enabled);
  }

  return (
    <>
      <Card>
        <CardHeader>
          <CardTitle className="text-base font-semibold">{t("jails.title")}</CardTitle>
          <CardDescription>{t("jails.description")}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {/* Asked, not told. The explanation matters once — on someone's first
              visit — and after that it is three lines of wall between them and
              the switches. A link costs one click for the person who needs it
              and nothing for the person who doesn't. */}
          <div>
            <button
              type="button"
              onClick={() => setExplaining((open) => !open)}
              aria-expanded={explaining}
              className="flex items-center gap-1.5 rounded text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
              <Info className="size-3.5" />
              {t("jails.whatIs")}
            </button>
            {explaining ? (
              <p className="mt-2 rounded-lg bg-muted/60 p-3 text-sm leading-relaxed text-muted-foreground">
                {t("jails.explainerBody")}
              </p>
            ) : null}
          </div>

          {/* "Nothing is switched on" used to be said here too. It fires on the
              exact same condition as the Set up protection card above, so the
              page said it twice — once as a warning with no action, once as a
              card with the button. One instruction, one place. */}

          {/* A grid, not a list: at full width one jail per row leaves most of
              the row empty, and a real install has five to eight of these. */}
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {jails.map((jail) => (
              <div
                key={jail.name}
                className={cn(
                  "rounded-lg border p-3",
                  // An attack in progress is the one thing on this page that wants
                  // to interrupt you.
                  jail.stats?.currently_failed > 0 && "border-warning/40 bg-warning/5",
                )}
              >
                {/* Name and switch on one line, stats under: in a narrow tile the
                    switch can't sit beside a block of numbers. */}
                <div className="flex items-start justify-between gap-2">
                  <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                    <span className="font-medium">{jail.label}</span>
                    <span className="font-mono text-xs text-muted-foreground">{jail.name}</span>
                    {jail.lockout_risk ? (
                      <Tooltip>
                        <TooltipTrigger asChild>
                          <span tabIndex={0} className="rounded">
                            <ShieldAlert className="size-3.5 text-warning" />
                          </span>
                        </TooltipTrigger>
                        <TooltipContent className="max-w-60">
                          {t("jails.lockoutHint")}
                        </TooltipContent>
                      </Tooltip>
                    ) : null}
                  </div>

                  <ReasonTooltip reason={canManage ? null : t("disabled.noPermission")}>
                    <PendingSwitch
                      checked={shown(jail)}
                      pending={pending === jail.name}
                      disabled={!canManage}
                      onCheckedChange={(next) => toggle(jail, next)}
                      aria-label={t("jails.toggle", { name: jail.label })}
                    />
                  </ReasonTooltip>
                </div>

                {/* One sentence, not four labelled numbers. Twelve figures
                    across three tiles is a dashboard nobody asked for; what you
                    need at a glance is whether this jail is doing anything and
                    whether anything is happening to it right now. The lifetime
                    totals are still there, one hover away, because they answer a
                    question you only ask deliberately. */}
                <JailState jail={jail} t={t} />
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      {/* Not dismissible mid-write: the config rewrite and reload are already
          under way, and a dialog that vanishes on Escape reads as "cancelled"
          when nothing was cancelled. */}
      <Dialog
        open={guarding !== null}
        onOpenChange={(open) => !open && pending === null && setGuarding(null)}
      >
        {/* Matches FormModal's width, because this is a form dialog in all but
            name — a labelled input, a hint, and two long buttons. On the bare
            `sm:max-w-sm` default it was the narrowest dialog in the panel while
            carrying the most content. */}
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

          {/* Editable, and the primary way out. The address is pre-filled from
              the API when it looks like a real client address, but it is the
              person at the keyboard who knows what they are connecting from —
              the panel only ever sees its own server. */}
          <div className="space-y-2 py-2">
            <Label htmlFor="lockout-ip">{t("lockout.ipLabel")}</Label>
            <Input
              id="lockout-ip"
              value={ignoreIp}
              onChange={(event) => setIgnoreIp(event.target.value)}
              placeholder={t("lockout.ipPlaceholder")}
              autoComplete="off"
              spellCheck={false}
              // Locked while the write is in flight: the address is part of the
              // payload already sent, so editing it here would change nothing
              // and say otherwise.
              disabled={pending !== null}
              className="font-mono text-xs"
            />
            <p className="text-xs text-muted-foreground">{t("lockout.ipHint")}</p>
          </div>

          <DialogFooter>
            {/* Deliberate order: the risky choice is the quiet one. In the
                default footer layout this reads left-to-right as quiet →
                primary, same as Cancel-then-Confirm everywhere else. */}
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

/**
 * What this jail is doing, in one line.
 *
 * Ordered by urgency: an attack in progress beats a standing block, which beats
 * "quiet". A switched-off jail counts nothing at all, so it reports that rather
 * than a row of zeroes that would read as "nothing is happening" — which is
 * true, but only because nobody is looking.
 */
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

  const line = <span className={cn("mt-2 block text-xs", tone)}>{text}</span>;

  // Nothing to reveal when the jail never reported totals.
  if (!jail.stats) return line;

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span tabIndex={0} className="rounded">
          {line}
        </span>
      </TooltipTrigger>
      <TooltipContent>
        {t("jails.totalsTooltip", {
          failed: jail.stats.total_failed ?? "—",
          banned: jail.stats.total_banned ?? "—",
        })}
      </TooltipContent>
    </Tooltip>
  );
}
