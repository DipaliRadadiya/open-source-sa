import { Info } from "lucide-react";
import { cn } from "@/lib/utils";
import { Popover, PopoverArrow, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { useHoverPopover } from "@/lib/hooks/use-hover-popover";

/**
 * An explanation that opens on hover (mouse), tap (touch) or keyboard Tab, but
 * not from focus a dialog handed over. A Popover because Radix tooltips never
 * open on touch; hover is added back only for hover-capable pointers. Styled
 * like a tooltip to match the rest of the panel.
 */
const TOOLTIP_SKIN =
  "w-auto max-w-xs gap-0 rounded-md bg-foreground px-3 py-1.5 text-xs text-background shadow-none ring-0";
export function InfoHint({ label, children, className }) {
  // Shared with the dashboard's site-health chip; do not copy the logic.
  const { open, onOpenChange, triggerProps, contentProps } = useHoverPopover({
    focusOpens: true,
  });

  return (
    <Popover open={open} onOpenChange={onOpenChange}>
      <PopoverTrigger
        type="button"
        data-slot="info-hint"
        aria-label={label}
        className={cn(
          "flex size-5 shrink-0 items-center justify-center rounded-full text-muted-foreground/70 transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none",
          className,
        )}
        // Often inside a row-wide <label>; stopPropagation keeps it from toggling
        // the row's control. Not preventDefault: Radix then skips its own handler.
        onClick={(event) => event.stopPropagation()}
        {...triggerProps}
      >
        <Info className="size-3.5" />
      </PopoverTrigger>
      <PopoverContent
        className={TOOLTIP_SKIN}
        // Keeps it open while the pointer moves from the icon to the panel.
        {...contentProps}
        // Hover-opened content must not steal focus.
        onOpenAutoFocus={(event) => event.preventDefault()}
        // Radix restores focus to the trigger on close, and the trigger's onFocus
        // would reopen it.
        onCloseAutoFocus={(event) => event.preventDefault()}
      >
        {children}
        {/* Matches the tooltip skin; `bg-popover` would leave a white pip. */}
        <PopoverArrow className="bg-foreground fill-foreground" />
      </PopoverContent>
    </Popover>
  );
}
