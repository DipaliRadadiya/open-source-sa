import { cn } from "@/lib/utils";
import { pct, usageTone } from "@/lib/metrics/usage-level";

const STROKE = {
  primary: "stroke-primary",
  warning: "stroke-warning",
  destructive: "stroke-destructive",
};

// A ring gauge with its reading in the middle. `percent` null draws the empty
// track only: nothing measured is not 0%.
export function UsageRing({ percent, label, children, size = 56, className }) {
  const stroke = 5;
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const filled = percent == null ? 0 : (pct(percent) / 100) * c;

  return (
    <div
      role={percent == null ? undefined : "progressbar"}
      aria-label={percent == null ? undefined : label}
      aria-valuenow={percent == null ? undefined : Math.round(pct(percent))}
      aria-valuemin={percent == null ? undefined : 0}
      aria-valuemax={percent == null ? undefined : 100}
      className={cn("relative shrink-0", className)}
      style={{ width: size, height: size }}
    >
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} className="-rotate-90" aria-hidden="true">
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" strokeWidth={stroke} className="stroke-primary/10" />
        {filled > 0 ? (
          <circle
            cx={size / 2}
            cy={size / 2}
            r={r}
            fill="none"
            strokeWidth={stroke}
            strokeLinecap="round"
            strokeDasharray={`${filled} ${c}`}
            className={cn(STROKE[usageTone(percent)], "transition-[stroke-dasharray] duration-500 motion-reduce:transition-none")}
          />
        ) : null}
      </svg>
      <span className="absolute inset-0 flex items-center justify-center text-[13px] font-semibold tabular-nums tracking-tight">
        {children}
      </span>
    </div>
  );
}
