"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Check, Database, Globe, Layers, Loader2, TriangleAlert, Zap } from "lucide-react";
import { cn } from "@/lib/utils";
import { createFirewallRule } from "@/lib/api/firewall";
import { riskyExposure } from "@/lib/firewall/exposure";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { apiMessage } from "@/lib/api/error-message";

// The ports a server actually needs opened, in the order people need them.
const QUICK_KEYS = ["http", "https", "ssh", "mysql"];
// One click, three rules — the set a web server needs to be useful at all.
const STACK_KEYS = ["http", "https", "ssh"];

/**
 * One click, one rule. No form.
 *
 * The overwhelming majority of firewall rules are "let HTTPS in" — a decision
 * with no parameters. Making that pass through a dialog with eight controls was
 * the actual usability problem; no amount of restyling the dialog fixes it.
 *
 * A tile that already exists as a rule says so and stops being clickable, so
 * this can't quietly produce duplicates or a 422 the user has to interpret.
 */
export function QuickAddCard({ presets, rules, canManage, sshPort, riskyPorts = [] }) {
  const t = useTranslations("firewall");
  const router = useRouter();
  const [pending, setPending] = useState(null);

  // SSH follows the port Settings configured, not the preset's 22. Hardcoding it
  // would open a port nobody listens on and report success, then lock the user
  // out of the port they actually use.
  const withRealPorts = presets
    .filter((p) => p.port != null)
    .map((p) => (p.key === "ssh" && sshPort ? { ...p, port: sshPort } : p));

  const byKey = new Map(withRealPorts.map((p) => [p.key, p]));
  const quick = QUICK_KEYS.map((k) => byKey.get(k)).filter(Boolean);
  const stack = STACK_KEYS.map((k) => byKey.get(k)).filter(Boolean);

  // "Already there" means an allow rule for that port and protocol, from
  // anywhere. Protocol has to match: a UDP rule on 443 is not the HTTPS rule,
  // and without this check the tile claimed "Added" for a rule that lets
  // nothing through — the worst kind of wrong, since it stops you adding the
  // real one. A rule restricted to one address is also a different rule.
  function exists(preset) {
    const wanted = preset.protocol || "tcp";
    return rules.some(
      (r) =>
        r.action === "allow" &&
        !r.source_ip &&
        !r.port_to &&
        Number(r.port_from) === Number(preset.port) &&
        // "all" covers both, so it satisfies a tcp or udp tile.
        ((r.protocol ?? "tcp") === wanted || r.protocol === "all"),
    );
  }

  async function add(items, label) {
    setPending(label);
    const created = [];
    const already = [];
    try {
      for (const preset of items) {
        if (exists(preset)) {
          already.push(preset.label);
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
          // A duplicate the local check missed is not a failure worth shouting
          // about; anything else is.
          if (error.response?.status === 422) already.push(preset.label);
          else throw error;
        }
      }

      // Say which of the two things happened. "Done" over a no-op is a lie.
      if (created.length) {
        toast.success(t("quick.added", { names: created.join(", ") }));
      } else if (already.length) {
        toast.info(t("quick.alreadyThere", { names: already.join(", ") }));
      }
      router.refresh();
    } catch (error) {
      const data = error.response?.data;
      toast.error([apiMessage(error, t("quick.failed")), data?.reference].filter(Boolean).join(" · "));
    } finally {
      setPending(null);
    }
  }

  if (quick.length === 0) return null;

  return (
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
          const done = exists(preset);
          // A database open to the whole internet is not a one-click decision.
          const risky = riskyExposure({ port: preset.port, riskyPorts });
          return (
            <Tile
              key={preset.key}
              icon={risky ? Database : Globe}
              risky={Boolean(risky) && !done}
              title={t("quick.tileTitle", { name: preset.label, port: preset.port })}
              // The subtitle always says what the rule DOES. Replacing it with
              // "Already allowed" threw away the only explanation on the tile,
              // exactly when someone new is trying to work out what it means.
              subtitle={
                risky && !done
                  ? t("quick.tileRisky", { name: risky })
                  : t("quick.tileBody", {
                      protocol: (preset.protocol || "tcp").toUpperCase(),
                      port: preset.port,
                    })
              }
              done={done}
              doneLabel={t("quick.tileDone")}
              disabled={!canManage || done || pending !== null}
              pending={pending === preset.key}
              onClick={() => add([preset], preset.key)}
            />
          );
        })}

        {stack.length > 1 ? (
          <Tile
            icon={Layers}
            title={t("quick.stackTitle")}
            subtitle={t("quick.stackBody", { names: stack.map((p) => p.label).join(" + ") })}
            doneLabel={t("quick.tileDone")}
            done={stack.every(exists)}
            disabled={!canManage || stack.every(exists) || pending !== null}
            pending={pending === "stack"}
            onClick={() => add(stack, "stack")}
          />
        ) : null}
      </CardContent>
    </Card>
  );
}

function Tile({ icon: Icon, title, subtitle, done, doneLabel, risky, disabled, pending, onClick }) {
  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      className={cn(
        "flex items-start gap-2.5 rounded-lg border px-3 py-2.5 text-left transition-colors",
        "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring",
        done
          ? "cursor-default border-success/30 bg-success/5"
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
            ? "bg-success/15 text-success"
            : risky
              ? "bg-warning/15 text-warning"
              : "bg-muted text-muted-foreground",
        )}
      >
        {pending ? (
          <Loader2 className="size-4 animate-spin" />
        ) : done ? (
          <Check className="size-4" />
        ) : (
          <Icon className="size-4" />
        )}
      </span>
      <span className="min-w-0 flex-1">
        <span className="flex items-center gap-1.5">
          <span className="min-w-0 truncate text-sm font-medium">{title}</span>
          {done ? (
            <span className="shrink-0 rounded bg-success/15 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-success">
              {doneLabel}
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
      </span>
    </button>
  );
}
