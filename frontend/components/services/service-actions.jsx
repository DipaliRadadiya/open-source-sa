"use client";

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
import { ConfigTestButton } from "@/components/services/config-test-button";
import { ServiceLogsLink } from "@/components/services/service-logs-link";
import { PhpSettingsLink } from "@/components/services/php-settings-link";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { apiMessage } from "@/lib/api/error-message";

// Ordered by how much they disturb the service: reload re-reads config without
// dropping a connection, restart drops everything for a moment, stop ends it.
// Colour follows that escalation — and it has to, because reload and restart
// are mirrored circular arrows that are indistinguishable in grey at 16px.
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

// Fixed slots, so the same action sits at the same x on every row and the
// column has a straight edge. Rows differ in which actions apply, and simply
// right-aligning them left the icons staggered down the table.
// `start` and `reload` share a slot because they never co-occur: start only
// appears when the service is down, reload only when it's up.
const SLOTS = [["start", "reload"], ["restart"], ["stop"]];

/**
 * Per-row controls: every applicable action visible as its own icon button, in
 * a fixed order so the same action sits in the same place down the column.
 *
 * No overflow menu — with at most three actions a menu hides half of them
 * behind a click and makes the rows unscannable. Labels live in tooltips and
 * `aria-label`, so the meaning is one hover (or one screen reader) away.
 *
 * Stop asks first: it takes something offline now, and undo can't give back the
 * seconds it was down. The rest just run.
 */
export function ServiceActions({
  service,
  canManage,
  phpVersion,
  onBusyChange,
  // Table: keep a column straight. Cards: hug the right edge.
  reserveSlots = true,
}) {
  const t = useTranslations("services");
  const router = useRouter();
  const [pending, setPending] = useState(null);
  const [confirming, setConfirming] = useState(null);

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
        message: apiMessage(error),
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
    <div className="flex items-center justify-end gap-0.5">
      {/* Read-only first, then the writes, with a rule between them. Looking at
          the log and checking the config are what you do BEFORE touching a
          running service — and they carry no colour, because on this row colour
          means "this changes the service". The divider is what stops the two
          groups reading as one undifferentiated strip of icons. */}
      {/* These collapse rather than hold reserved slots. Reserving space for
          all three left a service with none showing a large empty gap, and
          their exact x doesn't matter: they're a group that ends at the
          divider, not a column you read down. The ACTION buttons below are the
          ones that need fixed positions. */}
      {(service.log_keys ?? []).length > 0 ? <ServiceLogsLink service={service} /> : null}
      {service.testable ? <ConfigTestButton service={service} canManage={canManage} /> : null}
      {/* Settings for a PHP version live on the PHP page now — one place for
          the version, its extensions and its ini. Starting and stopping the FPM
          unit stays here, because that is the same job as for nginx. */}
      {phpVersion ? <PhpSettingsLink version={phpVersion} /> : null}
      {/* Only drawn when something sits to its left — a floating line with
          nothing before it reads as a rendering fault. */}
      {(service.log_keys ?? []).length > 0 || service.testable || phpVersion ? (
        <span aria-hidden="true" className="mx-1 h-5 w-px bg-border" />
      ) : null}

      {SLOTS.map((slot) => {
        const action = slot.find((a) => actions.includes(a));
        // A missing action holds its place only where the icons form a column
        // to read down — the table. In the cards the right edge is the
        // alignment guide, so an invisible trailing slot just insets the row by
        // 32px and reads as a mistake.
        if (!action) return reserveSlots ? <Slot key={slot[0]} /> : null;

        const meta = ACTION_META[action];
        const Icon = meta.icon;
        const label = t(`actions.${action}`);
        const disabled = !canManage || busy;

        return (
          <Tooltip key={slot[0]}>
            <TooltipTrigger asChild>
              {/* Wrapped: a disabled button swallows pointer events, and the
                  no-permission case is exactly when the tooltip matters. */}
              <span tabIndex={disabled ? 0 : -1} className="inline-flex">
                <Button
                  variant="ghost"
                  size="icon"
                  className={cn("size-8", meta.tone)}
                  disabled={disabled}
                  onClick={() => trigger(action)}
                  aria-label={label}
                >
                  {pending === action ? (
                    <Loader2 className="size-4 animate-spin" />
                  ) : (
                    <Icon className="size-4" />
                  )}
                </Button>
              </span>
            </TooltipTrigger>
            <TooltipContent>{canManage ? label : t("noPermission")}</TooltipContent>
          </Tooltip>
        );
      })}

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


/** A fixed 32px cell, so an absent action still holds its column. */
function Slot({ children }) {
  return <span className="inline-flex size-8 items-center justify-center">{children}</span>;
}
