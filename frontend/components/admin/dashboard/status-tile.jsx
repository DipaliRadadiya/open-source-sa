import Link from "@/components/ui/app-link";
import { ChevronRight } from "lucide-react";
import { cn } from "@/lib/utils";

// Drawn like the application page's status tiles: the colour lives in the icon tile (and
// the value, when something is wrong); the card itself stays white.
const TONES = {
  attention: { chip: "bg-destructive-soft text-destructive", value: "text-destructive" },
  warning: { chip: "bg-warning-soft text-warning", value: "text-[color-mix(in_oklch,var(--warning)_75%,var(--foreground))] dark:text-warning" },
  action: { chip: "bg-primary/10 text-primary", value: "" },
  good: { chip: "bg-success-soft text-success", value: "" },
  idle: { chip: "bg-muted text-muted-foreground", value: "" },
};

export function StatusTile({ icon: Icon, title, value, hint, tone = "idle", href }) {
  const { chip, value: valueTint } = TONES[tone] ?? TONES.idle;

  return (
    <Link
      href={href}
      prefetch={false}
      // The whole tile is the click target.
      className="group flex min-w-0 flex-col gap-3 rounded-2xl border border-border/70 bg-card p-4 shadow-e1 transition-[border-color,box-shadow] hover:border-primary/30 hover:shadow-e2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
    >
      <div className="flex items-center gap-3">
        <span className={cn("flex size-9 shrink-0 items-center justify-center rounded-xl", chip)}>
          <Icon className="size-[18px]" aria-hidden />
        </span>
        <p className="min-w-0 flex-1 text-xs font-medium break-words text-muted-foreground">{title}</p>
        <ChevronRight
          className="size-4 shrink-0 text-muted-foreground/60 transition-transform group-hover:translate-x-0.5"
          aria-hidden
        />
      </div>
      <div className="min-w-0">
        <p className={cn("text-[15px] font-semibold tracking-tight text-pretty break-words", valueTint)}>{value}</p>
        {hint ? <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p> : null}
      </div>
    </Link>
  );
}
