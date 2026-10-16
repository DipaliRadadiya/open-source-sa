import { Plus, Search } from "lucide-react";
import { cn } from "@/lib/utils";

// One recipe for every empty state, so 50-odd screens get a picture without 50 drawings.
// Steel UI (Krishna, 14 Oct): the subject's own icon in thin line work inside a bordered
// square with the banner's green corner brackets, and a small square badge saying what to
// do next. Brand colour comes from the theme, so a white-labelled panel recolours it.
const SIZES = {
  default: { box: "size-20", icon: "size-9", bracket: "size-3", badge: "-top-2 -right-2 size-7", glyph: "size-3.5" },
  compact: { box: "size-14", icon: "size-6", bracket: "size-2", badge: "-top-1.5 -right-1.5 size-5", glyph: "size-3" },
};

export function EmptyArt({ icon: Icon, badge = null, compact = false }) {
  const size = SIZES[compact ? "compact" : "default"];
  return (
    <div
      aria-hidden
      className={cn("relative flex shrink-0 items-center justify-center rounded-[2px] border bg-muted/30", size.box)}
    >
      <span className={cn("absolute -top-px -left-px border-t-2 border-l-2 border-primary", size.bracket)} />
      <span className={cn("absolute -right-px -bottom-px border-r-2 border-b-2 border-primary", size.bracket)} />
      <Icon className={cn("text-[var(--primary-700,var(--primary))] dark:text-primary", size.icon)} strokeWidth={1.25} />
      {badge === "add" || badge === "search" ? (
        <span
          className={cn(
            "absolute flex items-center justify-center rounded-[2px] border",
            badge === "add"
              ? "border-primary bg-primary text-primary-foreground"
              : "border-border bg-card text-[var(--primary-700,var(--primary))] dark:text-primary",
            size.badge,
          )}
        >
          {badge === "add" ? (
            <Plus className={size.glyph} strokeWidth={3} />
          ) : (
            <Search className={size.glyph} strokeWidth={2.5} />
          )}
        </span>
      ) : null}
    </div>
  );
}
