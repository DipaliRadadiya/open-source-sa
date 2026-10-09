import { Plus } from "lucide-react";
import { cn } from "@/lib/utils";

// One recipe for every empty state, so 50-odd screens get a picture without 50 drawings:
// the subject's own icon drawn large on the brand tint, a grey copy behind it, a soft
// shadow under it, and a badge saying what to do next. Brand colour comes from the
// theme, so a white-labelled panel recolours it for free.
const SIZES = {
  default: { box: "h-24 w-40", main: "size-16 top-4 left-8", ghost: "size-12 top-0.5 left-[5.25rem]", ground: "inset-x-6 h-3" },
  compact: { box: "h-16 w-28", main: "size-11 top-2.5 left-5", ghost: "size-8 top-0.5 left-14", ground: "inset-x-4 h-2" },
};

export function EmptyArt({ icon: Icon, badge = null, compact = false }) {
  const size = SIZES[compact ? "compact" : "default"];
  return (
    <div aria-hidden className={cn("relative shrink-0", size.box)}>
      <span className={cn("absolute bottom-0 rounded-[50%] bg-muted", size.ground)} />
      <Icon className={cn("absolute fill-muted/70 text-muted-foreground/30", size.ghost)} strokeWidth={1.4} />
      <Icon className={cn("absolute fill-primary/10 text-primary/65", size.main)} strokeWidth={1.4} />
      {badge === "add" ? (
        <span
          className={cn(
            "absolute flex items-center justify-center rounded-full bg-success text-white ring-4 ring-card",
            compact ? "top-0 right-4 size-5" : "-top-1 right-6 size-8",
          )}
        >
          <Plus className={compact ? "size-3" : "size-4"} strokeWidth={3} />
        </span>
      ) : null}
      {badge === "search" ? <Magnifier compact={compact} /> : null}
    </div>
  );
}

// A magnifier holding a question mark: "nothing matches what you asked for".
function Magnifier({ compact }) {
  return (
    <svg
      viewBox="0 0 40 40"
      className={cn("absolute text-primary", compact ? "top-5 right-2 size-9" : "top-8 right-3 size-14")}
      fill="none"
    >
      <circle cx="16" cy="16" r="11" className="fill-card" stroke="currentColor" strokeWidth="2.5" />
      <path d="M24.5 24.5 34 34" stroke="currentColor" strokeWidth="3.2" strokeLinecap="round" />
      <path
        d="M12.8 13.2a3.3 3.3 0 1 1 4.7 3c-.9.5-1.5 1.1-1.5 2.1"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
      />
      <circle cx="16" cy="21.6" r="1.2" fill="currentColor" />
    </svg>
  );
}
