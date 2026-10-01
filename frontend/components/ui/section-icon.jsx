import { cn } from "@/lib/utils";

// No tinted tile, so the brand colour keeps its meaning.
export function SectionIcon({ icon: Icon, className }) {
  return (
    <Icon
      aria-hidden
      className={cn("size-4 shrink-0 text-muted-foreground", className)}
    />
  );
}
