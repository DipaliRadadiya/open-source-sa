import { cn } from "@/lib/utils";

/** The tinted icon square at the start of a card's header band. */
export function CardIcon({ icon: Icon, tone = "primary", className }) {
  return (
    <span
      aria-hidden
      className={cn(
        "flex size-9 shrink-0 items-center justify-center rounded-lg",
        tone === "destructive" ? "bg-destructive/10 text-destructive" : "bg-primary/10 text-primary",
        className,
      )}
    >
      <Icon className="size-[18px]" />
    </span>
  );
}
