import { cn } from "@/lib/utils";
import { pct, usageTone } from "@/lib/metrics/usage-level";
import { PANEL_CARD } from "@/lib/theme/card-chrome";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

const STATUS_VARIANT = {
  normal: "success",
  watch: "warning",
  high: "destructive",
  off: "outline",
  unknown: "outline",
};

const TONE_STYLES = {
  primary: { chip: "bg-primary/10 text-primary", bar: "bg-primary" },
  warning: { chip: "bg-warning-soft text-warning", bar: "bg-warning" },
  destructive: { chip: "bg-destructive-soft text-destructive", bar: "bg-destructive" },
};

const TRACK = "bg-primary/8";

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
    <Card className={cn("gap-0 overflow-hidden py-0", PANEL_CARD)}>
      <CardContent className="@container/stat px-4 py-3.5">
        <div className="flex min-h-8 items-center gap-2.5">
          <span
            className={cn(
              "flex size-9 shrink-0 items-center justify-center rounded-xl",
              tone === "primary" ? "bg-primary/10 text-primary" : styles.chip,
            )}
          >
            <Icon className="size-[18px]" />
          </span>
          <span className="min-w-0 truncate text-xs font-medium text-muted-foreground">{label}</span>
        </div>

        {loading ? (
          <>
            <div className="mt-4 flex items-baseline justify-between gap-2">
              <Skeleton className="h-5 w-16" />
              <Skeleton className="h-4 w-24" />
            </div>
            <Skeleton className="mt-2.5 h-2 w-full rounded-full" />
            {hasSub ? <Skeleton className="mt-2.5 h-5 w-28" /> : null}
          </>
        ) : (
          <>
            <div className="mt-4 flex min-h-5 items-baseline justify-between gap-2 @max-[212px]/stat:flex-col @max-[212px]/stat:items-start @max-[212px]/stat:gap-y-1">
              <p className="text-xl font-semibold leading-none tracking-tight tabular-nums">
                {value}
              </p>
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
              <div className="mt-2.5 h-2" />
            )}

            {sub || status ? (
              <div className="mt-2.5 flex min-h-5 items-center justify-between gap-2">
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

function keepUnits(value) {
  return typeof value === "string" ? value.replace(/(\d)\s+(?=[A-Za-z%])/g, "$1\u00A0") : value;
}
