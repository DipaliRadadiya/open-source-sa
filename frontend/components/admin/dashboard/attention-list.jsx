import Link from "next/link";
import { getTranslations, getFormatter } from "next-intl/server";
import { ArrowRight, CircleX, Terminal, TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";
import { MAX_NAMES, summarizeAttention } from "@/lib/admin/attention-summary";

/**
 * Whether anything is urgent, what the biggest thing is, and where to go next.
 *
 * It used to print every failed check, every command failure and every warning
 * — seventeen rows of shell output on the page you open to find out whether you
 * need to do anything. That is a diagnostic report, and there are two pages
 * that already do it properly. Here each kind of problem gets ONE row: how many
 * there are, which ones (a few, by name), and the way through to the detail.
 *
 * At most three rows, because there are only three kinds.
 */
function Row({ tone, icon: Icon, title, summary, action, href }) {
  return (
    <li>
      <Link
        href={href}
        className="group flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3 transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
      >
        {/* A tinted square rather than a loose glyph: it gives the three rows a
            shared left edge to scan down, and the title lines up with it. */}
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

  // "Privileged commands, Services and Web server" — the API's own titles, in
  // the reader's language, with the tail counted rather than dropped.
  const nameList = (names) => {
    const shown = names.slice(0, MAX_NAMES);
    const rest = names.length - shown.length;
    // "A, B and C" when that is all of them; a plain comma list when a tail
    // follows, so it does not read "A, B, and C and 1 more".
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
      // Distinct problems, matching the Failures tile above it. Counting
      // occurrences instead put "100 recent command failures" under a tile
      // reading "11 recent errors" — two numbers for one thing, and the 100
      // was really just the size of the window we looked at.
      title: t("rowFailures", { count: failures.distinct }),
      // Only claimed when one stderr genuinely accounts for most of them;
      // otherwise say how many times they happened, which is true whatever
      // they were.
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
        {/* Text, not a link. These issues live on two different pages, so a
            single "view all" would have to pick one and be wrong about the
            rest; each row carries the destination that actually holds it. */}
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
