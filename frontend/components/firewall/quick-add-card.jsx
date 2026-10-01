"use client";

import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { toast } from "sonner";
import {
  Check,
  Database,
  Globe,
  Layers,
  Loader2,
  PowerOff,
  Trash2,
  TriangleAlert,
  Zap,
} from "lucide-react";
import { cn } from "@/lib/utils";
import {
  createFirewallRule,
  deleteFirewallRule,
  updateFirewallRule,
} from "@/lib/api/firewall";
import { riskyExposure } from "@/lib/firewall/exposure";
import { isPortOpen, matchRule } from "@/lib/firewall/quick-tiles";
import { deleteRuleBodyKey } from "@/lib/firewall/state";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { apiMessage } from "@/lib/api/error-message";

// The ports a server most often needs opened, in order.
const QUICK_KEYS = ["http", "https", "ssh", "mysql"];
// One click, three rules: the set a web server needs.
const STACK_KEYS = ["http", "https", "ssh"];

// A tile whose rule exists removes it on the next click, so it cannot create
// duplicates (422).
export function QuickAddCard({ presets, rules, enabled, canManage, sshPort, riskyPorts = [] }) {
  const t = useTranslations("firewall");
  const { refreshAndWait } = useRefresh();
  const [pending, setPending] = useState(null);
  const [confirming, setConfirming] = useState(null);
  const [opening, setOpening] = useState(null);

  // SSH follows the port set in Settings, not the preset's 22; otherwise it would
  // open an unused port and the user could be locked out.
  const withRealPorts = presets
    .filter((p) => p.port != null)
    .map((p) => (p.key === "ssh" && sshPort ? { ...p, port: sshPort } : p));

  const byKey = new Map(withRealPorts.map((p) => [p.key, p]));
  const quick = QUICK_KEYS.map((k) => byKey.get(k)).filter(Boolean);
  const stack = STACK_KEYS.map((k) => byKey.get(k)).filter(Boolean);

  const match = (preset) => matchRule(preset, rules);
  const isOn = (preset) => isPortOpen(preset, rules);

  async function add(items, label) {
    setPending(label);
    const created = [];
    const switchedOn = [];
    const already = [];
    let failed = null;
    try {
      for (const preset of items) {
        const existing = match(preset);
        if (existing) {
          // The rule exists but is off: enable it. A second one would 422, and deleting to
          // re-add would lose its other settings.
          if (existing.enabled === false) {
            await updateFirewallRule(existing.id, { enabled: true });
            switchedOn.push(preset.label);
          } else {
            already.push(preset.label);
          }
          continue;
        }
        try {
          await createFirewallRule({
            port_from: preset.port,
            protocol: preset.protocol || "tcp",
            action: "allow",
            description: preset.label,
          });
          created.push(preset.label);
        } catch (error) {
          // A duplicate the local check missed is not worth reporting; anything else is.
          if (error.response?.status === 422) already.push(preset.label);
          else throw error;
        }
      }
    } catch (error) {
      // No bulk endpoint, so this can stop halfway; record the failure and continue to
      // the refresh and toasts so the ports already opened are shown.
      failed = apiMessage(error, t("quick.failed"));
    } finally {
      // Always: rules may have been created even on failure. Before the toasts, so
      // "Added" never sits over a tile still offering the add.
      await refreshAndWait();
      // Report each outcome, including partial success.
      if (created.length) {
        toast.success(t("quick.added", { names: created.join(", ") }));
      }
      if (switchedOn.length) {
        toast.success(t("quick.switchedOn", { names: switchedOn.join(", ") }));
      } else if (!created.length && already.length && !failed) {
        toast.info(t("quick.alreadyThere", { names: already.join(", ") }));
      }
      if (failed) toast.error(failed);
      setPending(null);
      setOpening(null);
    }
  }

  async function remove() {
    const { rule, key } = confirming;
    setPending(key);
    try {
      await deleteFirewallRule(rule.id);
      await refreshAndWait();
      toast.success(t("rules.deleted"));
      setConfirming(null);
    } catch (error) {
      toast.error(
        apiMessage(error, t("rules.deleteFailed")),
      );
    } finally {
      setPending(null);
    }
  }

  if (quick.length === 0) return null;

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base font-semibold">
            <Zap className="size-4 text-primary" />
            {t("quick.title")}
          </CardTitle>
          <CardDescription>{t("quick.description")}</CardDescription>
        </CardHeader>
  
        <CardContent className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {quick.map((preset) => {
            const rule = match(preset);
            // Three states: missing, present-and-open, present-but-off. A disabled rule must
            // not show as "Added".
            const off = Boolean(rule) && rule.enabled === false;
            const done = Boolean(rule) && !off;
            // A database open to the whole internet is not a one-click decision.
            const risky = riskyExposure({ port: preset.port, riskyPorts });
            // Same lockout guard as the rules list: SSH and the panel's own ports stay while
            // the firewall is on, and the tile says why.
            const locked = done && Boolean(rule.protected) && enabled;
            const reason = !canManage
              ? t("disabled.noPermission")
              : locked
                ? t("rules.protectedReason")
                : null;
            return (
              <Tile
                key={preset.key}
                icon={risky ? Database : Globe}
                risky={Boolean(risky) && !done}
                title={t("quick.tileTitle", { name: preset.label, port: preset.port })}
                // The subtitle always says what the rule does.
                subtitle={
                  off
                    ? // Off: the rule allows nothing while disabled.
                      t("quick.tileOffBody", { port: preset.port })
                    : risky && !done
                      ? t("quick.tileRisky", { name: risky })
                      : t("quick.tileBody", {
                          protocol: (preset.protocol || "tcp").toUpperCase(),
                          port: preset.port,
                        })
                }
                done={done}
                doneLabel={t("quick.tileDone")}
                off={off}
                offLabel={t("rules.off")}
                removable={done && !locked && canManage}
                removeHint={t("quick.tileRemoveHint")}
                addHint={
                  canManage && !locked && (off || (!done && !risky))
                    ? t(off ? "quick.tileOffHint" : "quick.tileAddHint")
                    : null
                }
                reason={reason}
                disabled={!canManage || locked || pending !== null}
                pending={pending === preset.key}
                onClick={() =>
                  done
                    ? setConfirming({ rule, key: preset.key })
                    : risky && !off
                      ? setOpening({ preset, name: risky })
                      : add([preset], preset.key)
                }
              />
            );
          })}
  
          {stack.length > 1 ? (
            <Tile
              icon={Layers}
              title={t("quick.stackTitle")}
              subtitle={t("quick.stackBody", { names: stack.map((p) => p.label).join(" + ") })}
              doneLabel={t("quick.tileDone")}
              // Every port actually open, not merely present; otherwise the tile disables
              // itself while HTTP/HTTPS are switched off.
              done={stack.every(isOn)}
              addHint={!stack.every(isOn) && canManage ? t("quick.stackAddHint") : null}
              disabled={!canManage || stack.every(isOn) || pending !== null}
              pending={pending === "stack"}
              onClick={() => add(stack, "stack")}
            />
          ) : null}
        </CardContent>
  
        {/* Same confirmation as the rules list: this closes an open port, and tiles are
            easy to mis-click. */}
        <ConfirmDialog
          open={confirming !== null}
          onOpenChange={(open) => !pending && setConfirming(open ? confirming : null)}
          icon={Trash2}
          tone="destructive"
          title={t("rules.confirmTitle")}
          description={
            confirming
              ? t(deleteRuleBodyKey(enabled, confirming.rule), {
                  rule:
                    confirming.rule.description ||
                    confirming.rule.summary ||
                    confirming.rule.port_from,
                })
              : ""
          }
          cancelLabel={t("common.cancel")}
          confirmLabel={t("rules.delete")}
          pending={pending !== null}
          onConfirm={remove}
        />

        {/* Confirms opening a database port to everyone. */}
        <ConfirmDialog
          open={opening !== null}
          onOpenChange={(open) => !pending && setOpening(open ? opening : null)}
          icon={TriangleAlert}
          tone="warning"
          title={opening ? t("quick.riskyTitle", { name: opening.preset.label }) : ""}
          description={opening ? t("quick.riskyBody", { name: opening.name, port: opening.preset.port }) : ""}
          cancelLabel={t("common.cancel")}
          confirmLabel={t("quick.riskyConfirm")}
          confirmVariant="destructive"
          pending={pending !== null}
          onConfirm={() => add([opening.preset], opening.preset.key)}
        />
      </Card>
    </DisabledReasonProvider>
  );
}

function Tile({
  icon: Icon,
  title,
  subtitle,
  done,
  doneLabel,
  off,
  offLabel,
  risky,
  removable,
  removeHint,
  addHint,
  reason,
  disabled,
  pending,
  onClick,
}) {
  return (
    // "flex" wrapper + w-full tile: an inline-flex tooltip wrapper made locked tiles
    // narrower than the rest.
    <ReasonTooltip reason={reason} className="flex">
      <button
        type="button"
        disabled={disabled}
        onClick={onClick}
        className={cn(
          "group flex w-full items-start gap-2.5 rounded-lg border px-3 py-2.5 text-left transition-colors",
          "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring",
          done
            ? // Green at rest, red on hover: clicking removes the rule.
              removable
              ? "border-success/30 bg-success/5 hover:border-destructive/40 hover:bg-destructive/5"
              : "cursor-default border-success/30 bg-success/5"
            : off
              ? // Muted: the rule exists but is off; clicking reopens it.
                "border-muted-foreground/25 bg-muted/40 hover:border-primary/40 hover:bg-accent"
              : disabled
                ? "opacity-60"
                : risky
                  ? "border-warning/40 bg-warning/5 hover:border-warning hover:bg-warning/10"
                  : "hover:border-primary/40 hover:bg-accent",
        )}
      >
        <span
          className={cn(
            "flex size-7 shrink-0 items-center justify-center rounded-md",
            done
              ? removable
                ? "bg-success/15 text-success group-hover:bg-destructive/15 group-hover:text-destructive"
                : "bg-success/15 text-success"
              : off
                ? "bg-muted text-muted-foreground group-hover:bg-primary/15 group-hover:text-primary"
                : risky
                  ? "bg-warning/15 text-warning"
                  : "bg-muted text-muted-foreground group-hover:bg-primary/15 group-hover:text-primary",
          )}
        >
          {pending ? (
            <Loader2 className="size-4 animate-spin" />
          ) : off ? (
            <PowerOff className="size-4" />
          ) : done ? (
            <>
              <Check className={cn("size-4", removable && "group-hover:hidden")} />
              {removable ? <Trash2 className="hidden size-4 group-hover:block" /> : null}
            </>
          ) : (
            <Icon className="size-4" />
          )}
        </span>
        <span className="min-w-0 flex-1">
          <span className="flex items-center gap-1.5">
            <span className="min-w-0 truncate text-sm font-medium">{title}</span>
            {done ? (
              <span className="shrink-0 rounded bg-success/15 px-1.5 py-0.5 text-xs font-medium uppercase tracking-wide text-success">
                {doneLabel}
              </span>
            ) : off ? (
              <span className="shrink-0 rounded bg-muted-foreground/15 px-1.5 py-0.5 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                {offLabel}
              </span>
            ) : risky ? (
              <TriangleAlert className="size-3.5 shrink-0 text-warning" />
            ) : null}
          </span>
          <span
            className={cn(
              "block text-xs leading-relaxed",
              risky ? "text-warning" : "text-muted-foreground",
            )}
          >
            {subtitle}
          </span>
          {/* Stated, not left to hover (touch has none): both states explain the click. */}
          {removable ? (
            <span className="block text-xs text-muted-foreground group-hover:text-destructive">
              {removeHint}
            </span>
          ) : addHint ? (
            <span className="block text-xs text-muted-foreground group-hover:text-primary">
              {addHint}
            </span>
          ) : null}
        </span>
      </button>
    </ReasonTooltip>
  );
}
