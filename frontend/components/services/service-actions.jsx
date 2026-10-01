import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
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
import Link from "@/components/ui/app-link";
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
// dropping connections, restart drops everything briefly, stop ends it. Colour
// follows that escalation.
const ACTION_META = {
  start: { icon: Play, tone: "text-success hover:bg-success/10 hover:text-success" },
  reload: { icon: RefreshCcw, tone: "text-primary hover:bg-primary/10 hover:text-primary" },
  restart: { icon: RotateCw, tone: "text-warning hover:bg-warning/10 hover:text-warning" },
  stop: {
    icon: Square,
    tone: "text-destructive hover:bg-destructive/10 hover:text-destructive",
  },
};

// Which verbs apply to the service's current state.
const BY_STATUS = {
  active: ["reload", "restart", "stop"],
  inactive: ["start"],
  failed: ["start", "restart"],
};

// The one action the row leads with, by state. Everything else is in the menu.
// `failed` leads with start: recovery means bringing it up.
const PRIMARY = { active: "restart", inactive: "start", failed: "start" };

/**
 * Per-row controls: one labelled button for the state-appropriate action, and
 * a named menu for the rest (same shape as FileRowActions). Stop sits in the
 * menu, away from Restart.
 *
 * Stop asks first: it takes something offline now, and undo can't give back the
 * seconds it was down. Restart of a running unit asks too: it drops every
 * connection. Start and reload just run.
 */
export function ServiceActions({ service, canManage, phpVersion, onBusyChange }) {
  const t = useTranslations("services");
  const { refreshAndWait } = useRefresh();
  const [pending, setPending] = useState(null);
  const [confirming, setConfirming] = useState(null);
  const configTest = useConfigTest(service);

  // Reported upward so the status cell can say "Restarting…" too.
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

  // If the state's primary is not permitted (e.g. a reload-only protected
  // unit), fall back to whatever is allowed.
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
      // Re-read before the toast, so the row's state matches it.
      await refreshAndWait();
      showActionSuccess({
        title: t(`toast.${action}`, { name: service.label }),
        // Undo for Stop, the action most likely to be regretted.
        undoLabel: action === 'stop' ? t('undoStop') : undefined,
        onUndo: action === 'stop' ? () => run('start') : undefined,
      });
    } catch (error) {
      const data = error.response?.data;
      // Name the service and action, and say the state is unchanged; the API
      // message alone does not.
      showActionError({
        // No answer at all (connection dropped) is not "left as it was": the
        // server may have done it. The list re-reads every 3 s and shows which.
        title: error.response
          ? t(`error.${action}`, { name: service.label })
          : t("error.noAnswer", { name: service.label }),
        // The title already says there was no answer.
        message: error.response ? apiMessage(error, undefined, { reference: false }) : undefined,
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
    // Restarting a running unit drops every connection; a failed unit has
    // nothing to drop, so it just runs.
    const disruptive =
      DISRUPTIVE_ACTIONS.includes(action) ||
      (action === "restart" && service.status === "active");

    if (disruptive) setConfirming(action);
    else run(action);
  }

  const confirmIsRestart = confirming === "restart";

  return (
    <div className="flex items-center justify-end gap-1.5">
      {/* The state's verb on one button: Restart when running, Start otherwise. */}
      {primary ? (
        <Tooltip>
          <TooltipTrigger asChild>
            {/* Wrapped: a disabled button swallows pointer events, and the
                no-permission case is exactly when the tooltip matters. */}
            <span tabIndex={!canManage || busy ? 0 : -1} className="inline-flex">
              <Button
                // Neutral, not outline: inside a card the outline variant
                // turns blue and overrides the verb's colour.
                variant="neutral"
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
            {/* The remaining state verbs, each labelled. */}
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

            {/* Read-only checks (logs, config test) below the verbs, after a rule. */}
            {secondary.length > 0 && (hasLogs || service.testable || phpVersion) ? (
              <DropdownMenuSeparator />
            ) : null}

            <ServiceLogItems service={service} />

            {service.testable ? (
              <DropdownMenuItem
                // A read: it runs `nginx -t` / `php-fpm -t` and changes nothing,
                // and the API allows it with view access.
                disabled={configTest.pending}
                // Closing the menu is fine: the dialog is rendered outside it.
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

            {/* PHP version settings live on the PHP page; starting and
                stopping the FPM unit stays here. */}
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
        // Restart brings the service back by itself, so it warns rather than
        // alarms; Stop leaves it down.
        icon={confirmIsRestart ? RotateCw : TriangleAlert}
        tone={confirmIsRestart ? "warning" : "destructive"}
        title={confirming ? t(`confirm.${confirming}.title`, { name: service.label }) : ""}
        description={
          confirming ? t(`confirm.${confirming}.description`, { name: service.label }) : ""
        }
        cancelLabel={t("confirm.cancel")}
        confirmLabel={confirming ? t(`actions.${confirming}`) : ""}
        confirmVariant={confirmIsRestart ? "default" : "destructive"}
        pending={busy}
        onConfirm={() => run(confirming)}
      />
    </div>
  );
}
