import { cn } from "@/lib/utils";

/**
 * The icon beside a section heading: a plain muted icon, no tinted tile, so
 * the brand colour keeps its meaning and the heading carries the weight.
 */
export function SectionIcon({ icon: Icon, className }) {
  return (
    <Icon
      aria-hidden
      className={cn("size-4 shrink-0 text-muted-foreground", className)}
    />
  );
}
