import Link from "next/link";
import { getTranslations } from "next-intl/server";
import { CircleX, TriangleAlert, Terminal, ChevronRight } from "lucide-react";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";

/**
 * What is wrong, spelled out, on the page you land on.
 *
 * Two different sources — failed installation checks and recorded failures —
 * because from the reader's side they are the same question. Splitting them
 * into two screens means the answer to "is anything broken" is spread across
 * System Health and the Error Log, and the dashboard between them said neither.
 *
 * The evidence is shown, not just the count: "Privileged commands failed" sends
 * you looking, while "not permitted: useradd, systemctl" is often the whole
 * diagnosis. Capped at six, with the overflow named rather than dropped.
 */
const MAX_ROWS = 6;

function Row({ tone, icon: Icon, title, detail, meta, href }) {
  return (
    <li>
      <Link
        href={href}
        className="group flex items-start gap-3 px-5 py-3 transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
      >
        <Icon
          className={cn(
            "mt-0.5 size-4 shrink-0",
            tone === "fail" ? "text-destructive" : "text-warning",
          )}
          aria-hidden
        />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
            <p
              className={cn(
                "text-sm font-medium",
                tone === "fail" ? "text-destructive" : "text-warning",
              )}
            >
              {title}
            </p>
            {meta ? <p className="text-xs text-muted-foreground">{meta}</p> : null}
          </div>
          {/* Wraps rather than truncates: this line is the diagnosis, and
              "not permitted: useradd, systemctl, ufw, apt-ge…" cuts off at
              exactly the point where it starts being useful. */}
          {detail ? (
            <p className="mt-0.5 font-mono text-xs break-words text-muted-foreground">{detail}</p>
          ) : null}
        </div>
        <ChevronRight
          className="mt-0.5 size-4 shrink-0 text-muted-foreground/50 transition-transform group-hover:translate-x-0.5"
          aria-hidden
        />
      </Link>
    </li>
  );
}

export async function AttentionList({ checks = [], errorGroups = [] }) {
  const t = await getTranslations("admin.attention");

  const rows = [
    ...checks.map((c) => ({
      key: `check-${c.key}`,
      tone: c.status === "fail" ? "fail" : "warn",
      icon: c.status === "fail" ? CircleX : TriangleAlert,
      title: c.title,
      detail: c.detail,
      meta: t(c.status === "fail" ? "checkFailed" : "checkWarning"),
      href: "/admin/doctor",
    })),
    ...errorGroups.map((g) => ({
      key: `error-${g.key}`,
      tone: "warn",
      icon: Terminal,
      // Named by what broke, in the same words the Error Log uses.
      title:
        g.kind === "operation"
          ? `${g.feature ?? "?"} · ${g.operation ?? "?"}`
          : `${g.method ?? "?"} ${g.route ?? "?"}`,
      detail: g.occurrences[0]?.error ?? g.exceptionShort ?? null,
      meta: g.count > 1 ? t("occurrences", { count: g.count }) : null,
      href: "/admin/error-logs",
    })),
  ];

  if (!rows.length) return null;

  const shown = rows.slice(0, MAX_ROWS);
  const hidden = rows.length - shown.length;

  return (
    <Card className="gap-0 overflow-hidden py-0 shadow-sm">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b px-5 py-3.5">
        <h2 className="font-heading text-base leading-snug font-semibold tracking-tight">
          {t("title")}
        </h2>
        <p className="text-sm text-muted-foreground">{t("count", { count: rows.length })}</p>
      </div>
      <ul className="divide-y">
        {shown.map((row) => (
          <Row key={row.key} {...row} />
        ))}
      </ul>
      {/* Never a silent cap: a list that quietly stops at six reads as "six is
          all there is". */}
      {hidden > 0 ? (
        <div className="border-t bg-muted/20 px-5 py-2.5">
          <p className="text-xs text-muted-foreground">{t("more", { count: hidden })}</p>
        </div>
      ) : null}
    </Card>
  );
}
