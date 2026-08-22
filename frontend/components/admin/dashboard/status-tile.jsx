import Link from "next/link";
import { ChevronRight } from "lucide-react";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";

/**
 * One thing that can be wrong, and where to go about it.
 *
 * The dashboard this replaced showed four counts — total users, total roles,
 * total activity — none of which anyone acts on. These four say whether the
 * panel needs you, so every one of them is a link: reading "4 checks failed"
 * and then having to find System Health in the sidebar is the same dead end as
 * not being told at all.
 *
 * Severity is an accent down the left edge and a tinted icon, never a filled
 * card. Four saturated cards in a row is a traffic light nobody can read —
 * every tile shouts and none of them wins. With a 3px edge the eye still sorts
 * them instantly, and the numbers stay legible foreground text.
 */
const TONES = {
  attention: { chip: "bg-destructive/10 text-destructive", accent: "bg-destructive/50", value: "text-destructive", card: "bg-destructive/[0.02]" },
  warning: { chip: "bg-warning/10 text-warning", accent: "bg-warning/50", value: "", card: "" },
  action: { chip: "bg-primary/10 text-primary", accent: "bg-primary/50", value: "", card: "" },
  good: { chip: "bg-success/10 text-success", accent: "bg-success/45", value: "", card: "" },
  idle: { chip: "bg-muted text-muted-foreground", accent: "bg-border", value: "", card: "" },
};

export function StatusTile({ icon: Icon, title, value, hint, tone = "idle", href }) {
  const { chip, accent, card, value: valueTint } = TONES[tone] ?? TONES.idle;

  return (
    <Card
      className={cn(
        "group relative gap-0 overflow-hidden py-0 shadow-sm transition-colors hover:bg-muted/40",
        card,
      )}
    >
      <span className={cn("absolute inset-y-0 left-0 w-[2px]", accent)} aria-hidden />
      <Link
        href={href}
        // The whole tile is the target: a 4px chevron is not a click area.
        className="flex h-full flex-col gap-2 rounded-xl py-4 pr-4 pl-5 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
      >
        <div className="flex items-center gap-2">
          <span className={cn("flex size-7 shrink-0 items-center justify-center rounded-md", chip)}>
            <Icon className="size-3.5" aria-hidden />
          </span>
          <p className="min-w-0 flex-1 text-xs font-medium tracking-wide text-balance text-muted-foreground uppercase">
            {title}
          </p>
          <ChevronRight
            className="size-4 shrink-0 text-muted-foreground/50 transition-transform group-hover:translate-x-0.5"
            aria-hidden
          />
        </div>
        <p className={cn("text-base leading-tight font-semibold tracking-tight", valueTint)}>
          {value}
        </p>
        {hint ? <p className="mt-auto text-xs text-muted-foreground">{hint}</p> : null}
      </Link>
    </Card>
  );
}
