import { cn } from "@/lib/utils";

// The icon chip at the start of a card's heading, so headings stand apart from content.
// Colour sits in the chip, never the title.
export const HEAD_ICON_TONES = {
  default: "border-primary/45 bg-primary/10 text-[var(--primary-700,var(--primary))] dark:text-primary",
  destructive: "border-destructive/45 bg-destructive/10 text-destructive",
};

export function HeadIcon({ icon: Icon, tone = "default", className }) {
  if (!Icon) return null;
  return (
    <span
      data-slot="head-icon"
      aria-hidden
      className={cn("flex size-9 shrink-0 items-center justify-center rounded-[2px] border", HEAD_ICON_TONES[tone] ?? HEAD_ICON_TONES.default, className)}
    >
      <Icon className="size-[18px]" />
    </span>
  );
}
