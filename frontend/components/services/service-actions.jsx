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

const ACTION_META = {
  start: { icon: Play, tone: "text-success hover:bg-success/10 hover:text-success" },
  reload: { icon: RefreshCcw, tone: "text-primary hover:bg-primary/10 hover:text-primary" },
  restart: { icon: RotateCw, tone: "text-warning hover:bg-warning/10 hover:text-warning" },
  stop: {
    icon: Square,
    tone: "text-destructive hover:bg-destructive/10 hover:text-destructive",
  },
};

const BY_STATUS = {
  active: ["reload", "restart", "stop"],
  inactive: ["start"],
  failed: ["start", "restart"],
};

const PRIMARY = { active: "restart", inactive: "start", failed: "start" };

export function ServiceActions({ service, canManage, phpVersion, onBusyChange }) {
  const t = useTranslations("services");
  const { refreshAndWait } = useRefresh();
  const [pending, setPending] = useState(null);
  const [confirming, setConfirming] = useState(null);
  const configTest = useConfigTest(service);

  function setBusyAction(action) {
    setPending(action);
    onBusyChange?.(action);
  }

  const allowed = service.actions ?? [];
  const actions = canManage
    ? (BY_STATUS[service.status] ?? ["restart"]).filter((a) => allowed.includes(a))
    : [];
  const busy = pending !== null;

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
      await refreshAndWait();
      showActionSuccess({
        title: t(`toast.${action}`, { name: service.label }),
        undoLabel: action === 'stop' ? t('undoStop') : undefined,
        onUndo: action === 'stop' ? () => run('start') : undefined,
      });
    } catch (error) {
      const data = error.response?.data;
      showActionError({
        title: error.response
          ? t(`error.${action}`, { name: service.label })
          : t("error.noAnswer", { name: service.label }),
        message: error.response ? apiMessage(error, undefined, { reference: false }) : undefined,
        reference: data?.reference,
        copyLabel: t('copyReference'),
        copiedLabel: t('copiedReference'),
        retryLabel: t('retry'),
        onRetry: () => run(action),
      });
    } finally {
      setBusyAction(null);
    }
  }

  function trigger(action) {
    const disruptive =
      DISRUPTIVE_ACTIONS.includes(action) ||
      (action === "restart" && service.status === "active");

    if (disruptive) setConfirming(action);
    else run(action);
  }

  const confirmIsRestart = confirming === "restart";

  return (
    <div className="flex items-center justify-end gap-1.5">
      {primary ? (
        <Tooltip>
          <TooltipTrigger asChild>
            <span tabIndex={busy ? 0 : -1} className="inline-flex">
              <Button
                variant="neutral"
                size="sm"
                className={cn(primary === "restart" ? null : ACTION_META[primary].tone)}
                disabled={busy}
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
            {secondary.map((action) => {
              const Icon = ACTION_META[action].icon;

              return (
                <DropdownMenuItem
                  key={action}
                  onSelect={() => trigger(action)}
                  variant={action === "stop" ? "destructive" : undefined}
                >
                  <Icon className="size-4" />
                  {t(`actions.${action}`)}
                </DropdownMenuItem>
              );
            })}

            {secondary.length > 0 && (hasLogs || service.testable || phpVersion) ? (
              <DropdownMenuSeparator />
            ) : null}

            <ServiceLogItems service={service} />

            {service.testable ? (
              <DropdownMenuItem
                disabled={configTest.pending}
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
        open={configTest.open}
        pending={configTest.pending}
        result={configTest.result}
        onDismiss={configTest.dismiss}
      />

      <ConfirmDialog
        open={confirming !== null}
        onOpenChange={(open) => !open && setConfirming(null)}
        icon={confirmIsRestart ? RotateCw : TriangleAlert}
        tone={confirmIsRestart ? "warning" : "destructive"}
        title={confirming ? t(`confirm.${confirming}.title`, { name: service.label }) : ""}
        description={
          confirming ? t(`confirm.${confirming}.description`, { name: service.label }) : ""
        }
        cancelLabel={t("confirm.cancel")}
        confirmLabel={confirming ? t(`actions.${confirming}`) : ""}
        confirmVariant={confirmIsRestart ? "default" : "destructive"}
        onConfirm={() => {
          const action = confirming;
          setConfirming(null);
          run(action);
        }}
      />
    </div>
  );
}
