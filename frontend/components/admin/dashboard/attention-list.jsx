import Link from "@/components/ui/app-link";
import { getTranslations, getFormatter } from "next-intl/server";
import { ArrowRight, CircleX, Terminal, TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";
import { MAX_NAMES, summarizeAttention } from "@/lib/admin/attention-summary";

/**
 * Whether anything is urgent and where to go next: one row per kind of problem
 * (at most three) with a count, a few names, and a link to the detail page.
 */
function Row({ tone, icon: Icon, title, summary, action, href }) {
  return (
    <li>
      <Link
        href={href}
        className="group flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3 transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
      >
        <span
          className={cn(
            "flex size-8 shrink-0 items-center justify-center rounded-lg",
            tone === "fail" ? "bg-destructive/10" : "bg-warning/10",
          )}
        >
          <Icon
            className={cn("size-4", tone === "fail" ? "text-destructive" : "text-warning")}
            aria-hidden
          />
        </span>
        <div className="min-w-64 flex-1 space-y-0.5">
          <p
            className={cn(
              "text-sm font-medium",
              tone === "fail" ? "text-destructive" : "text-foreground",
            )}
          >
            {title}
          </p>
          {summary ? <p className="text-sm text-muted-foreground">{summary}</p> : null}
        </div>
        <span className="inline-flex shrink-0 items-center gap-1 text-sm font-medium text-primary">
          {action}
          <ArrowRight
            className="size-3.5 transition-transform group-hover:translate-x-0.5"
            aria-hidden
          />
        </span>
      </Link>
    </li>
  );
}

export async function AttentionList({ checks = [], errorGroups = [] }) {
  const [t, format] = await Promise.all([
    getTranslations("admin.attention"),
    getFormatter(),
  ]);

  const { failed, warnings, failures, total } = summarizeAttention({ checks, errorGroups });
  if (!total) return null;

  // The API's localised titles, with the remainder counted.
  const nameList = (names) => {
    const shown = names.slice(0, MAX_NAMES);
    const rest = names.length - shown.length;
    // "A, B and C" when complete; a plain comma list before "and N more".
    const joined = format.list(shown, { type: rest > 0 ? "unit" : "conjunction" });
    return rest > 0 ? t("namesMore", { names: joined, count: rest }) : joined;
  };

  const rows = [];

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

  if (failures.count) {
    rows.push({
      key: "failures",
      tone: "warn",
      icon: Terminal,
      // Distinct problems, matching the Failures tile.
      title: t("rowFailures", { count: failures.distinct }),
      // A reason only when one stderr accounts for most; otherwise the count.
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

  return (
    <Card className="gap-0 overflow-hidden py-0 shadow-sm">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b px-5 py-3.5">
        <h2 className="font-heading text-base leading-snug font-semibold tracking-tight">
          {t("title")}
        </h2>
        {/* Text, not a link: the issues live on two pages; each row links its own. */}
        <p className="text-sm text-muted-foreground">{t("count", { count: total })}</p>
      </div>
      <ul className="divide-y">
        {rows.map((row) => (
          <Row key={row.key} {...row} />
        ))}
      </ul>
    </Card>
  );
}
