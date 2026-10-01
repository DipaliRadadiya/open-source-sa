import { cn } from "@/lib/utils";
import { pct, usageTone } from "@/lib/metrics/usage-level";
import { PANEL_CARD } from "@/lib/theme/card-chrome";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

// Nothing-to-measure is a hollow outline, so "no swap" never reads as healthy.
const STATUS_VARIANT = {
  normal: "success",
  watch: "warning",
  high: "destructive",
  off: "outline",
  unknown: "outline",
};

// Only the chip and bar carry status colour. The inset ring gives the low-alpha chip an edge.
const TONE_STYLES = {
  primary: { chip: "bg-primary/15 text-primary ring-primary/25", bar: "bg-primary" },
  warning: { chip: "bg-warning/20 text-warning ring-warning/30", bar: "bg-warning" },
  destructive: {
    chip: "bg-destructive/15 text-destructive ring-destructive/25",
    bar: "bg-destructive",
  },
};

/* One brand-tinted track for every tone, so bars in a row look like one component. */
const TRACK = "bg-primary/8";

// `status` is opt-in: `{ key, label }`, already translated by the caller.
export function StatCard({
  icon: Icon,
  label,
  value,
  hint,
  sub,
  percent,
  loading,
  hasSub,
  status = null,
}) {
  const tone = percent != null ? usageTone(percent) : "primary";
  const styles = TONE_STYLES[tone];

  return (
    // py-0 cancels Card's own padding. PANEL_CARD: see lib/theme/card-chrome.js.
    <Card className={cn("gap-0 overflow-hidden bg-gradient-to-t from-primary/5 to-card py-0", PANEL_CARD)}>
      {/* Container query, not flex-wrap, so equal-width cards stack on the same tick. */}
      <CardContent className="@container/stat px-4 py-3.5">
        {/* The label gets the whole row; the status badge lives in the bottom row
            because sharing this one clipped labels in most locales. */}
        <div className="flex min-h-8 items-center gap-2.5">
          {/* Tinted chip only when not normal, so a warning stands out. */}
          <span
            className={cn(
              "flex size-8 shrink-0 items-center justify-center rounded-lg",
              tone === "primary"
                ? "text-muted-foreground"
                : cn("ring-1 ring-inset", styles.chip),
            )}
          >
            <Icon className="size-4" />
          </span>
          <span className="min-w-0 truncate text-sm font-medium text-muted-foreground">{label}</span>
        </div>

        {/* Skeleton mirrors the loaded card line for line. */}
        {loading ? (
          <>
            <div className="mt-4 flex items-baseline justify-between gap-2">
              <Skeleton className="h-5 w-16" />
              <Skeleton className="h-4 w-24" />
            </div>
            {/* Same height and offset as the real bar to avoid a layout shift. */}
            <Skeleton className="mt-2.5 h-2 w-full rounded-full" />
            {hasSub ? <Skeleton className="mt-2.5 h-5 w-28" /> : null}
          </>
        ) : (
          <>
            {/* min-h keeps the row height equal with or without a hint. */}
            <div className="mt-4 flex min-h-5 items-baseline justify-between gap-2 @max-[212px]/stat:flex-col @max-[212px]/stat:items-start @max-[212px]/stat:gap-y-1">
              <p className="text-xl font-semibold leading-none tracking-tight tabular-nums">
                {value}
              </p>
              {/* leading-none to match the value; half-leading on items-baseline adds height. */}
              <span className="shrink-0 text-sm leading-none tabular-nums text-muted-foreground @max-[212px]/stat:shrink">
                {hint}
              </span>
            </div>

            {percent != null ? (
              <div
                role="progressbar"
                aria-label={label}
                aria-valuenow={Math.round(pct(percent))}
                aria-valuemin={0}
                aria-valuemax={100}
                className={cn("mt-2.5 h-2 w-full overflow-hidden rounded-full", TRACK)}
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
              // Reserves the bar's space so cards stay the same height.
              <div className="mt-2.5 h-2" />
            )}

            {/* Helper line and status share the bottom row; min-h-5 keeps card heights equal. */}
            {sub || status ? (
              <div className="mt-2.5 flex min-h-5 items-center justify-between gap-2">
                {/* Wraps rather than truncates: the number at the end must stay visible. */}
                <p className="min-w-0 text-xs break-words tabular-nums text-muted-foreground">
                  {keepUnits(sub)}
                </p>
                {status ? (
                  <Badge
                    variant={STATUS_VARIANT[status.key] ?? "outline"}
                    className="shrink-0 font-normal"
                  >
                    {status.label}
                  </Badge>
                ) : null}
              </div>
            ) : null}
          </>
        )}
      </CardContent>
    </Card>
  );
}

// "3.8 GB" wraps as a unit, never as "3.8 / GB".
function keepUnits(value) {
  return typeof value === "string" ? value.replace(/(\d)\s+(?=[A-Za-z%])/g, "$1\u00A0") : value;
}
