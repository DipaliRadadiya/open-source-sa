"use client";

import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { ArrowRight, ChevronRight, TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { SectionJumpLink } from "@/components/ui/section-jump-link";

// The icon sits in a square edged in its tone.
const TONE = {
  success: { tile: "border-success/45 bg-success/10 text-success", value: "text-foreground" },
  warning: { tile: "border-warning/45 bg-warning/10 text-warning", value: "text-[color-mix(in_oklch,var(--warning)_75%,var(--foreground))] dark:text-warning" },
  destructive: { tile: "border-destructive/45 bg-destructive/10 text-destructive", value: "text-destructive" },
  info: { tile: "border-primary/45 bg-primary/10 text-[var(--primary-700,var(--primary))] dark:text-primary", value: "text-foreground" },
  neutral: { tile: "border-border bg-muted/40 text-muted-foreground", value: "text-foreground" },
};
// The label in mono caps, like the dashboard's stat cells.
const LABEL = "font-mono text-xs font-medium tracking-[0.1em] break-words hyphens-auto text-muted-foreground uppercase";

// Also drawn on the Services page; `action` sits where a linked tile shows its chevron.
// `compact` puts label and value beside the icon in one row, for short values that
// left the stacked tile mostly empty.
export function Tile({ icon: Icon, label, value, detail, tone, href, action, compact = false }) {
  const t = TONE[tone] ?? TONE.neutral;
  const end = href ? (
    <ChevronRight className="size-4 shrink-0 text-muted-foreground/60 transition-transform group-hover:translate-x-0.5" aria-hidden />
  ) : (
    action ?? null
  );
  const body = compact ? (
    <div className="flex items-center gap-3">
      <span className={cn("flex size-10 shrink-0 items-center justify-center rounded-[2px] border", t.tile)}>
        <Icon className="size-5" aria-hidden />
      </span>
      <div className="min-w-0 flex-1">
        {/* hyphens-auto: a long German compound broke mid-word ("Handlungsbed / arf") in a 2×2 phone grid. */}
        <p className={LABEL}>{label}</p>
        <p className={cn("text-[15px] font-semibold tracking-tight text-pretty break-words", t.value)}>{value}</p>
        {detail ? <p className="line-clamp-2 text-xs text-muted-foreground">{detail}</p> : null}
      </div>
      {end}
    </div>
  ) : (
    <>
      <div className="flex items-center gap-3">
        <span className={cn("flex size-9 shrink-0 items-center justify-center rounded-[2px] border", t.tile)}>
          <Icon className="size-[18px]" aria-hidden />
        </span>
        <span className={cn("min-w-0 flex-1", LABEL)}>{label}</span>
        {end}
      </div>
      <div className="min-w-0">
        {/* Wraps, never truncates: German "Deployment fehlgeschlagen" was cut mid-word. */}
        <p className={cn("text-[15px] font-semibold tracking-tight text-pretty break-words", t.value)}>{value}</p>
        {detail ? <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{detail}</p> : null}
      </div>
    </>
  );
  const className = cn(
    "group flex min-w-0 flex-col rounded-2xl border border-border/70 bg-card shadow-e1 transition-[border-color,box-shadow]",
    compact ? "justify-center px-4 py-3" : "gap-3 p-4",
  );
  return href ? (
    <Link
      href={href}
      prefetch={false}
      className={cn(className, "hover:border-primary/30 hover:shadow-e2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring")}
    >
      {body}
    </Link>
  ) : (
    <div className={className}>{body}</div>
  );
}

// What needs attention on an application's overview, one line each with its fix.
export function AttentionList({ alerts = [] }) {
  const t = useTranslations("applications");
  if (!alerts.length) return null;
  return (
    <ul className="grid gap-2 lg:grid-cols-[repeat(auto-fit,minmax(26rem,1fr))]" aria-label={t("attention.title")}>
      {alerts.map((item) => (
        <li
          key={item.key}
          className="flex min-w-0 items-center gap-3 rounded-xl bg-card px-3 py-2.5 shadow-e1 ring-1 ring-border/70"
        >
          <span className="flex size-8 shrink-0 items-center justify-center rounded-xl bg-warning-soft text-warning">
            <TriangleAlert className="size-4" aria-hidden />
          </span>
          <p className="min-w-0 flex-1 text-sm font-medium wrap-anywhere">{item.label}</p>
          {item.action && item.href ? (
            item.href.startsWith("#") ? (
              <SectionJumpLink href={item.href} className="inline-flex shrink-0 items-center gap-1 text-sm font-medium text-primary hover:underline">
                {item.action}
                <ArrowRight className="size-3.5" aria-hidden />
              </SectionJumpLink>
            ) : (
              <Link
                href={item.href}
                prefetch={false}
                className="inline-flex shrink-0 items-center gap-1 text-sm font-medium text-primary hover:underline"
              >
                {item.action}
                <ArrowRight className="size-3.5" aria-hidden />
              </Link>
            )
          ) : null}
        </li>
      ))}
    </ul>
  );
}
