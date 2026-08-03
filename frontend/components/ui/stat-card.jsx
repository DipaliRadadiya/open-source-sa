"use client";

import { cn } from "@/lib/utils";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

export function pct(value) {
  const n = Number(value);
  return Number.isFinite(n) ? Math.max(0, Math.min(100, n)) : 0;
}

// Usage thresholds: healthy < 75 < busy < 90 <= critical.
export function usageTone(percent) {
  const p = pct(percent);
  if (p >= 90) return "destructive";
  if (p >= 75) return "warning";
  return "primary";
}

// Only the icon chip and the bar carry the status color — the value stays
// foreground so the numbers read cleanly.
const TONE_STYLES = {
  primary: { chip: "bg-primary/10 text-primary", bar: "bg-primary", track: "bg-primary/15" },
  warning: { chip: "bg-warning/15 text-warning", bar: "bg-warning", track: "bg-warning/20" },
  destructive: {
    chip: "bg-destructive/10 text-destructive",
    bar: "bg-destructive",
    track: "bg-destructive/20",
  },
};

/**
 * One measured number with a usage bar.
 *
 * Lives here rather than inside the dashboard because the disk figure on the
 * Disk Cleaner page is the same statistic in the same product — rebuilding it
 * by eye produced a card that drifted on every pass (different value size,
 * different icon treatment) until the two pages disagreed about what a
 * percentage looks like. Shared code can't drift.
 */
export function StatCard({ icon: Icon, label, value, hint, sub, percent, loading, hasSub }) {
  const styles = TONE_STYLES[percent != null ? usageTone(percent) : "primary"];

  return (
    // py-0 cancels Card's own vertical padding so CardContent controls it.
    <Card className="gap-0 overflow-hidden bg-gradient-to-t from-primary/5 to-card py-0 shadow-sm">
      <CardContent className="px-4 py-3.5">
        <div className="flex items-center gap-2">
          <span
            className={cn("flex size-7 shrink-0 items-center justify-center rounded-md", styles.chip)}
          >
            <Icon className="size-3.5" />
          </span>
          <span className="text-sm font-medium text-muted-foreground">{label}</span>
        </div>

        {/* The skeleton mirrors the loaded card line for line — value, hint, bar
            and sub. Skeletoning only the number let the other three appear from
            nowhere. */}
        {loading ? (
          <>
            <div className="mt-4 flex items-baseline justify-between gap-2">
              <Skeleton className="h-5 w-16" />
              <Skeleton className="h-4 w-24" />
            </div>
            <Skeleton className="mt-2 h-1.5 w-full rounded-full" />
            {hasSub ? <Skeleton className="mt-2 h-3 w-28" /> : null}
          </>
        ) : (
          <>
            <div className="mt-4 flex items-baseline justify-between gap-2">
              <p className="text-lg font-semibold leading-none tracking-tight tabular-nums">
                {value}
              </p>
              <span className="shrink-0 text-sm tabular-nums text-muted-foreground">{hint}</span>
            </div>

            {percent != null ? (
              <div
                role="progressbar"
                aria-label={label}
                aria-valuenow={Math.round(pct(percent))}
                aria-valuemin={0}
                aria-valuemax={100}
                className={cn("mt-2 h-1.5 w-full overflow-hidden rounded-full", styles.track)}
              >
                <div
                  className={cn(
                    "h-full rounded-full transition-[width] duration-500 motion-reduce:transition-none",
                    styles.bar,
                  )}
                  style={{ width: `${pct(percent)}%` }}
                />
              </div>
            ) : (
              <div className="mt-2 h-1.5" />
            )}

            {sub ? (
              <p className="mt-2 truncate text-xs tabular-nums text-muted-foreground">{sub}</p>
            ) : null}
          </>
        )}
      </CardContent>
    </Card>
  );
}
