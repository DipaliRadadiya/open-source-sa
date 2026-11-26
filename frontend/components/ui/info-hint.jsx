import { Info } from "lucide-react";
import { cn } from "@/lib/utils";
import { Popover, PopoverArrow, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { useHoverPopover } from "@/lib/hooks/use-hover-popover";

const TOOLTIP_SKIN =
  "w-auto max-w-xs gap-0 rounded-md bg-foreground px-3 py-1.5 text-xs text-background shadow-none ring-0";
export function InfoHint({ label, children, className }) {
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
        // stopPropagation so a row-wide <label> doesn't toggle; not preventDefault, or Radix skips its handler.
        onClick={(event) => event.stopPropagation()}
        {...triggerProps}
      >
        <Info className="size-3.5" />
      </PopoverTrigger>
      <PopoverContent
        className={TOOLTIP_SKIN}
        {...contentProps}
        // Hover-opened content must not steal focus.
        onOpenAutoFocus={(event) => event.preventDefault()}
        // Radix refocuses the trigger on close, and its onFocus would reopen this.
        onCloseAutoFocus={(event) => event.preventDefault()}
      >
        {children}
        <PopoverArrow className="bg-foreground fill-foreground" />
      </PopoverContent>
    </Popover>
  );
}
