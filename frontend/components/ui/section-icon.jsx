import { cn } from "@/lib/utils";

export function SectionIcon({ icon: Icon, className }) {
  return (
    <Icon
      aria-hidden
      className={cn("size-4 shrink-0 text-muted-foreground", className)}
    />
  );
}
