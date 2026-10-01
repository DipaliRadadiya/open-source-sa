import { TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";

// Colour lives on the icon and surface; the text stays foreground for readability.
// Red uses a lower tint than amber on purpose: it reads hotter at equal opacity.
const SURFACE = {
  // Soft tint and hairline border; the icon carries the colour.
  warning: "border-warning/25 bg-warning/5",
  destructive: "border-destructive/25 bg-destructive/[0.03]",
};
const MARK = { warning: "text-warning", destructive: "text-destructive" };
const SIZE = {
  sm: { box: "gap-2 px-2.5 py-2 text-xs", icon: "mt-px size-3.5" },
  md: { box: "gap-2.5 px-3 py-2.5 text-sm", icon: "mt-0.5 size-4" },
};

export function Caution({
  tone = "warning",
  size = "sm",
  icon: Icon = TriangleAlert,
  action = null,
  children,
  className,
}) {
  const scale = SIZE[size] ?? SIZE.sm;
  return (
    // A <div>, not a <p>: callers may pass buttons or paragraphs.
    <div
      data-slot="caution"
      className={cn(
        "flex rounded-lg border",
        // With an action the row wraps, so the button drops below on a phone
        // instead of squeezing the text.
        action ? "flex-wrap items-center" : "items-start",
        scale.box,
        SURFACE[tone] ?? SURFACE.warning,
        className,
      )}
    >
      <Icon className={cn("shrink-0", scale.icon, action && "mt-0 self-start sm:self-center", MARK[tone] ?? MARK.warning)} aria-hidden />
      {/* max-w-prose for readability, except beside an action button. */}
      <div className={cn("flex-1 space-y-2", action ? "min-w-48" : "min-w-0 [&>p]:max-w-prose")}>{children}</div>
      {action ? <div className="shrink-0">{action}</div> : null}
    </div>
  );
}
