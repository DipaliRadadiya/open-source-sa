import Link from "@/components/ui/app-link";
import { getTranslations, getFormatter } from "next-intl/server";
import { ArrowRight, CircleX, Terminal, TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { MAX_NAMES, summarizeAttention } from "@/lib/admin/attention-summary";

// One slim line per kind of problem (at most three), linking to its detail page: the
// same rows as the server dashboard's attention panel.
function Row({ tone, icon: Icon, title, summary, action, href }) {
  return (
    <li>
      <Link
        href={href}
        prefetch={false}
        title={summary ?? undefined}
        className="group flex min-w-0 items-center gap-3 rounded-xl bg-card px-3 py-2.5 shadow-e1 ring-1 ring-border/70 transition-colors hover:ring-primary/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
      >
        <span
          className={cn(
            "flex size-8 shrink-0 items-center justify-center rounded-xl",
            tone === "fail" ? "bg-destructive-soft text-destructive" : "bg-warning-soft text-warning",
          )}
        >
          <Icon className="size-4" aria-hidden />
        </span>
        <p className="flex min-w-0 flex-1 items-center gap-1.5 text-sm leading-snug">
          {/* The names give way first, so the problem itself stays readable. */}
          <span className={cn("min-w-0 font-medium break-words", tone === "fail" && "text-destructive")}>{title}</span>
          {summary ? (
            <span className="hidden min-w-0 shrink-[6] truncate text-muted-foreground md:inline">· {summary}</span>
          ) : null}
          {summary ? <span className="sr-only md:hidden">{summary}</span> : null}
        </p>
        <span className="inline-flex shrink-0 items-center gap-1 text-sm font-medium text-primary">
          {action}
          <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" aria-hidden />
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
    // No card or heading of its own, as on the server dashboard: the tiles above already
    // say something is wrong; these lines say what, and where to fix it.
    <section aria-label={t("title")}>
      {/* One per line: at most three rows, and the reason after each title needs the width. */}
      <ul className="grid gap-2">
        {rows.map((row) => (
          <Row key={row.key} {...row} />
        ))}
      </ul>
    </section>
  );
}
