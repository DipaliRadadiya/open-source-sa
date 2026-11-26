import Link from "@/components/ui/app-link";
import { getTranslations, getFormatter } from "next-intl/server";
import { ArrowRight, ArrowUpCircle, CircleX, ShieldAlert, Terminal, TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { MAX_NAMES, summarizeAttention } from "@/lib/admin/attention-summary";
import { updateAttention } from "@/lib/admin/dashboard-facts";
import { FAILED_SIGN_IN_ALERT } from "@/lib/admin/sign-in-facts";
import { HoverHint } from "@/components/ui/hover-hint";

function Row({ tone, icon: Icon, title, summary, action, href }) {
  return (
    <li>
      <HoverHint content={summary ?? undefined}><Link
        href={href}
        prefetch={false}
        className="group flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1.5 rounded-xl bg-card px-3 py-2.5 shadow-e1 ring-1 ring-border/70 transition-colors hover:ring-primary/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
      >
        <span
          className={cn(
            "flex size-8 shrink-0 items-center justify-center rounded-xl",
            tone === "fail" ? "bg-destructive-soft text-destructive" : "bg-warning-soft text-warning",
          )}
        >
          <Icon className="size-4" aria-hidden />
        </span>
        <p className="flex min-w-48 flex-1 items-center gap-1.5 text-sm leading-snug">
          <span className={cn("min-w-0 font-medium break-words md:shrink-0 md:whitespace-nowrap", tone === "fail" && "text-destructive")}>{title}</span>
          {summary ? (
            <span className="hidden min-w-0 shrink-[6] truncate text-muted-foreground md:inline">· {summary}</span>
          ) : null}
          {summary ? <span className="sr-only md:hidden">{summary}</span> : null}
        </p>
        <span className="ml-auto inline-flex shrink-0 items-center gap-1 text-sm font-medium text-primary">
          {action}
          <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" aria-hidden />
        </span>
      </Link></HoverHint>
    </li>
  );
}

export async function AttentionList({ checks = [], errorGroups = [], update = null, signIns = null }) {
  const [t, tUpdate, format] = await Promise.all([
    getTranslations("admin.attention"),
    getTranslations("admin.panelUpdate"),
    getFormatter(),
  ]);

  const { failed, warnings, failures } = summarizeAttention({ checks, errorGroups });

  const nameList = (names) => {
    const shown = names.slice(0, MAX_NAMES);
    const rest = names.length - shown.length;
    const joined = format.list(shown, { type: rest > 0 ? "unit" : "conjunction" });
    return rest > 0 ? t("namesMore", { names: joined, count: rest }) : joined;
  };

  const rows = [];

  const updateRows = updateAttention(update);

  if (updateRows.failed) {
    rows.push({
      key: "update-failed",
      tone: "fail",
      icon: ArrowUpCircle,
      title: t("rowUpdateFailed"),
      summary: updateRows.failed.reason,
      action: t("openUpdate"),
      href: "/admin/panel-update",
    });
  }

  if (failed.count) {
    rows.push({
      key: "failed",
      tone: "fail",
      icon: CircleX,
      title: t("rowFailed", { count: failed.count }),
      summary: nameList(failed.names),
      action: t("openHealth"),
      href: "/admin/doctor",
    });
  }

  if (signIns && signIns.day >= FAILED_SIGN_IN_ALERT) {
    rows.push({
      key: "sign-ins",
      tone: "warn",
      icon: ShieldAlert,
      title: t("rowSignIns", { count: signIns.day }),
      summary: signIns.address ? t("signInsFrom", { address: signIns.address.value }) : null,
      action: t("openSignIns"),
      href: "/admin/activity-log?security=1",
    });
  }

  if (failures.count) {
    rows.push({
      key: "failures",
      tone: "warn",
      icon: Terminal,
      title: t("rowFailures", { count: failures.distinct }),
      summary: failures.reason
        ? t("failuresReason", { reason: failures.reason })
        : t("failuresOccurrences", { count: failures.count }),
      action: t("openErrors"),
      href: "/admin/error-logs",
    });
  }

  if (warnings.count) {
    rows.push({
      key: "warnings",
      tone: "warn",
      icon: TriangleAlert,
      title: t("rowWarnings", { count: warnings.count }),
      summary: nameList(warnings.names),
      action: t("openHealth"),
      href: "/admin/doctor",
    });
  }

  if (updateRows.blocked) {
    const { version, keys } = updateRows.blocked;
    rows.push({
      key: "update-blocked",
      tone: "warn",
      icon: ArrowUpCircle,
      title: t("rowUpdateBlocked", { version }),
      summary: keys.length
        ? nameList(keys.map((key) => (tUpdate.has(`preflight.${key}`) ? tUpdate(`preflight.${key}`) : key)))
        : null,
      action: t("openUpdate"),
      href: "/admin/panel-update",
    });
  }

  if (!rows.length) return null;

  return (
    <section aria-label={t("title")}>
      <h2 className="mb-2 font-mono text-xs font-medium tracking-[0.1em] text-muted-foreground uppercase">
        {t("heading", { count: rows.length })}
      </h2>
      <ul className="grid grid-cols-1 gap-2">
        {rows.map((row) => (
          <Row key={row.key} {...row} />
        ))}
      </ul>
    </section>
  );
}
