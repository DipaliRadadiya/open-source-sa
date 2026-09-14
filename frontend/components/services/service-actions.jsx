import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import {
  RotateCw,
  Play,
  Square,
  RefreshCcw,
  Loader2,
  TriangleAlert,
  MoreHorizontal,
  ShieldCheck,
  SlidersHorizontal,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { runServiceAction } from "@/lib/api/services";
import {
  showActionError,
  showActionSuccess,
} from "@/components/services/service-toast";
import { DISRUPTIVE_ACTIONS } from "@/lib/schemas/service";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ConfigTestDialog, useConfigTest } from "@/components/services/config-test";
import { ServiceLogItems } from "@/components/services/service-log-items";
import Link from "next/link";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { apiMessage } from "@/lib/api/error-message";

// Ordered by how much they disturb the service: reload re-reads config without
// dropping a connection, restart drops everything for a moment, stop ends it.
// Colour follows that escalation. It used to be load-bearing, because reload
// and restart were mirrored circular arrows sitting side by side and colour was
// the only thing telling them apart. Both carry their own word now, so the
// colour is reinforcement rather than the whole signal.
const ACTION_META = {
  start: { icon: Play, tone: "text-success hover:bg-success/10 hover:text-success" },
  reload: { icon: RefreshCcw, tone: "text-primary hover:bg-primary/10 hover:text-primary" },
  restart: { icon: RotateCw, tone: "text-warning hover:bg-warning/10 hover:text-warning" },
  stop: {
    icon: Square,
    tone: "text-destructive hover:bg-destructive/10 hover:text-destructive",
  },
};

// Which verbs apply to the state the service is actually in. A stopped unit
// showing Stop, or a running one showing Start, is noise the reader has to
// filter out on every row.
const BY_STATUS = {
  active: ["reload", "restart", "stop"],
  inactive: ["start"],
  failed: ["start", "restart"],
};

// The one action the row leads with, by state. Everything else is in the menu.
//
// `failed` leads with start rather than restart: recovery from failed is
// "bring it up", and restart is a click away for the case where it is not.
const PRIMARY = { active: "restart", inactive: "start", failed: "start" };

/**
 * Per-row controls: one labelled button for the action you actually want, and a
 * menu for the rest.
 *
 * **This was six icon-only buttons per row** — logs, config test, PHP settings,
 * then start/reload, restart, stop — and it defended itself with "no overflow
 * menu, with at most three actions a menu hides half of them". That counted the
 * three state verbs and ignored the three links beside them. Six grey glyphs of
 * the same size, no text, and reload and restart adjacent as near-identical
 * circular arrows: a row you had to hover through to read, and on a touch
 * screen could not read at all.
 *
 * So: the verb that matches the state gets a word and sits on the row, and
 * everything else is a named item behind `…` — the same shape FileRowActions
 * uses, which is the panel's own convention for this.
 *
 * **Stop is in the menu.** It already asked for confirmation, so it was never
 * one click; what it gains is not being one pixel from Restart.
 *
 * Stop asks first: it takes something offline now, and undo can't give back the
 * seconds it was down. The rest just run.
 */
export function ServiceActions({ service, canManage, phpVersion, onBusyChange }) {
  const t = useTranslations("services");
  const router = useRouter();
  const [pending, setPending] = useState(null);
  const [confirming, setConfirming] = useState(null);
  const configTest = useConfigTest(service);

  // Reported upward so the status cell can say "Restarting…" too. A spinner on
  // one icon while the badge still reads "Running" leaves the row ambiguous
  // about whether anything is actually happening.
  function setBusyAction(action) {
    setPending(action);
    onBusyChange?.(action);
  }

  const allowed = service.actions ?? [];
  // Intersected with what the API permits for THIS service, so a protected unit
  // never shows Stop no matter what state it's in.
  const actions = (BY_STATUS[service.status] ?? ["restart"]).filter((a) =>
    allowed.includes(a),
  );
  const busy = pending !== null;

  // The row's one button, and everything else. A state whose primary is not
  // permitted for this unit — a protected service that may only be reloaded —
  // falls back to whatever it does allow rather than showing nothing.
  const primary = actions.includes(PRIMARY[service.status])
    ? PRIMARY[service.status]
    : (actions[0] ?? null);
  const secondary = actions.filter((a) => a !== primary);

  const hasLogs = (service.log_keys ?? []).length > 0;
  const hasMenu =
    secondary.length > 0 || hasLogs || service.testable || Boolean(phpVersion);

  async function run(action) {
    setBusyAction(action);
    try {
      await runServiceAction(service.key, action);
      showActionSuccess({
        title: t(`toast.${action}`, { name: service.label }),
        // Stop is the one action here you might regret the instant it lands.
        // Undo beats hunting for the row and picking the right icon again.
        undoLabel: action === 'stop' ? t('undoStop') : undefined,
        onUndo: action === 'stop' ? () => run('start') : undefined,
      });
      router.refresh();
    } catch (error) {
      const data = error.response?.data;
      // Name the service and the action, and say the state is unchanged — the
      // server's own "the operation failed" says none of that, and the first
      // question after a failed restart is "so is it still up?".
      showActionError({
        title: t(`error.${action}`, { name: service.label }),
        message: apiMessage(error, undefined, { reference: false }),
        reference: data?.reference,
        copyLabel: t('copyReference'),
        copiedLabel: t('copiedReference'),
        retryLabel: t('retry'),
        onRetry: () => run(action),
      });
    } finally {
      setBusyAction(null);
      setConfirming(null);
    }
  }

  function trigger(action) {
    if (DISRUPTIVE_ACTIONS.includes(action)) setConfirming(action);
    else run(action);
  }

  return (
    <div className="flex items-center justify-end gap-1.5">
      {/* One button, with the verb on it. Which verb depends on the state, so
          the thing you came to do is the thing under the cursor: Restart a
          running unit, Start a stopped or failed one. */}
      {primary ? (
        <Tooltip>
          <TooltipTrigger asChild>
            {/* Wrapped: a disabled button swallows pointer events, and the
                no-permission case is exactly when the tooltip matters. */}
            <span tabIndex={!canManage || busy ? 0 : -1} className="inline-flex">
              <Button
                variant="outline"
                size="sm"
                className={cn(ACTION_META[primary].tone)}
                disabled={!canManage || busy}
                onClick={() => trigger(primary)}
              >
                {pending === primary ? (
                  <Loader2 className="size-4 animate-spin" />
                ) : (
                  (() => {
                    const Icon = ACTION_META[primary].icon;

                    return <Icon className="size-4" />;
                  })()
                )}
                {t(`actions.${primary}`)}
              </Button>
            </span>
          </TooltipTrigger>
          {canManage ? null : <TooltipContent>{t("noPermission")}</TooltipContent>}
        </Tooltip>
      ) : null}

      {hasMenu ? (
        <DropdownMenu>
          <Tooltip>
            <TooltipTrigger asChild>
              <DropdownMenuTrigger asChild>
                <Button
                  variant="ghost"
                  size="icon"
                  className="size-8"
                  aria-label={t("moreActions", { name: service.label })}
                  disabled={busy}
                >
                  <MoreHorizontal className="size-4" />
                </Button>
              </DropdownMenuTrigger>
            </TooltipTrigger>
            <TooltipContent>{t("moreActions", { name: service.label })}</TooltipContent>
          </Tooltip>

          <DropdownMenuContent align="end" className="w-52">
            {/* The remaining state verbs, each with its word. Reload and
                restart can finally sit near each other: one says "Reload", the
                other says "Restart". */}
            {secondary.map((action) => {
              const Icon = ACTION_META[action].icon;

              return (
                <DropdownMenuItem
                  key={action}
                  disabled={!canManage}
                  onSelect={() => trigger(action)}
                  variant={action === "stop" ? "destructive" : undefined}
                >
                  <Icon className="size-4" />
                  {t(`actions.${action}`)}
                </DropdownMenuItem>
              );
            })}

            {/* Reading before writing: the log and the config check are what
                you do BEFORE touching a running service, so they sit below the
                verbs with a rule between. */}
            {secondary.length > 0 && (hasLogs || service.testable || phpVersion) ? (
              <DropdownMenuSeparator />
            ) : null}

            <ServiceLogItems service={service} />

            {service.testable ? (
              <DropdownMenuItem
                disabled={!canManage || configTest.pending}
                // Closing the menu is what we want — the dialog is rendered
                // below, outside it, so it survives.
                onSelect={() => configTest.run()}
              >
                {configTest.pending ? (
                  <Loader2 className="size-4 animate-spin" />
                ) : (
                  <ShieldCheck className="size-4" />
                )}
                {t("configTest.action")}
              </DropdownMenuItem>
            ) : null}

            {/* Settings for a PHP version live on the PHP page now — one place
                for the version, its extensions and its ini. Starting and
                stopping the FPM unit stays here, because that is the same job
                as for nginx. */}
            {phpVersion ? (
              <DropdownMenuItem asChild>
                <Link href={`/php?version=${encodeURIComponent(phpVersion)}`}>
                  <SlidersHorizontal className="size-4" />
                  {t("phpSettings")}
                </Link>
              </DropdownMenuItem>
            ) : null}
          </DropdownMenuContent>
        </DropdownMenu>
      ) : null}

      <ConfigTestDialog
        service={service}
        result={configTest.result}
        onDismiss={configTest.dismiss}
      />

      <ConfirmDialog
        open={confirming !== null}
        onOpenChange={(open) => !open && setConfirming(null)}
        icon={TriangleAlert}
        tone="destructive"
        title={confirming ? t(`confirm.${confirming}.title`, { name: service.label }) : ""}
        description={confirming ? t(`confirm.${confirming}.description`) : ""}
        cancelLabel={t("confirm.cancel")}
        confirmLabel={confirming ? t(`actions.${confirming}`) : ""}
        confirmVariant="destructive"
        pending={busy}
        onConfirm={() => run(confirming)}
      />
    </div>
  );
}
