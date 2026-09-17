import { useRef, useState } from "react";
import { Info } from "lucide-react";
import { cn } from "@/lib/utils";
import { Popover, PopoverArrow, PopoverContent, PopoverTrigger } from "@/components/ui/popover";

/**
 * An explanation that opens on hover with a mouse, on tap with a finger, and
 * on a keyboard Tab onto it — but never from focus a dialog handed over.
 *
 * A popover rather than a tooltip, because Radix tooltips are hover- and
 * focus-only and dismiss themselves on pointer-down — on a touch screen the
 * note would be unreachable. The hover behaviour a tooltip would have given is
 * added back here, gated on the device actually having a hover-capable pointer,
 * so a tap on a phone still opens it and a stray touch on a hybrid device does
 * not open it twice.
 *
 * It is dressed as a tooltip because that is what it is. The mechanism had to
 * change for touch; the appearance did not, and letting it keep the popover's
 * white card put two different-looking panels on one screen doing the same job
 * — a disabled button explaining itself in dark, a field explaining itself in
 * white. The panel has ~120 tooltips and four of these, so this is the one
 * that moves.
 */
const TOOLTIP_SKIN =
  "w-auto max-w-xs gap-0 rounded-md bg-foreground px-3 py-1.5 text-xs text-background shadow-none ring-0";
export function InfoHint({ label, children, className }) {
  const [open, setOpen] = useState(false);
  // Cancelled when the pointer lands on the panel, so crossing the gap between
  // the icon and its own content does not close it mid-move.
  const closeTimer = useRef(null);

  const canHover = () =>
    typeof window !== "undefined" && window.matchMedia("(hover: hover)").matches;

  const openOnHover = () => {
    if (!canHover()) return;
    clearTimeout(closeTimer.current);
    setOpen(true);
  };

  const closeOnLeave = () => {
    if (!canHover()) return;
    closeTimer.current = setTimeout(() => setOpen(false), 120);
  };

  /*
   * Focus opens this only when the focus came from the keyboard.
   *
   * A dialog hands focus to the first thing it can reach, and this icon sits
   * ahead of its own field — so on every dialog whose first label carries a
   * hint (about 18 of them), opening it popped this note over the control
   * underneath, unasked. Reported on Storage → Add Destination, where it
   * covered the Provider dropdown completely.
   *
   * `:focus-visible` is the distinction the browser already draws and the one
   * that matters here: programmatic focus after a mouse click does not match
   * it, a Tab onto the icon does. So the noise goes and the keyboard route
   * stays — which is the whole reason focus opens this at all, since someone
   * on a keyboard cannot hover.
   */
  const openOnKeyboardFocus = (event) => {
    if (!event.currentTarget.matches(":focus-visible")) return;
    openOnHover();
  };

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger
        type="button"
        aria-label={label}
        className={cn(
          // A hit target, not punctuation. Sitting flush against the label at
          // full opacity it read as part of the sentence; the ring on hover is
          // what says "this is a thing you can press".
          "flex size-5 shrink-0 items-center justify-center rounded-full text-muted-foreground/70 transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none",
          className,
        )}
        // Often sits inside a row-wide <label>; without this, opening the note
        // would also toggle the row's control. It has to be stopPropagation
        // rather than preventDefault — Radix skips its own click handler when
        // the default is prevented, so the popover would never open.
        onClick={(event) => event.stopPropagation()}
        onMouseEnter={openOnHover}
        onMouseLeave={closeOnLeave}
        onFocus={openOnKeyboardFocus}
        onBlur={closeOnLeave}
      >
        <Info className="size-3.5" />
      </PopoverTrigger>
      <PopoverContent
        className={TOOLTIP_SKIN}
        // Without this the panel is unreadable with a mouse: it would close the
        // moment the pointer left the icon to reach it.
        onMouseEnter={openOnHover}
        onMouseLeave={closeOnLeave}
        // Hover-opened content must not steal focus, or the row's control loses
        // it every time the pointer passes over the icon.
        onOpenAutoFocus={(event) => event.preventDefault()}
        // Closing a hover-opened Popover makes Radix restore focus to its
        // trigger. That trigger's onFocus opens this hint, causing a close →
        // reopen loop. Focus never leaves the trigger here, so keep it there.
        onCloseAutoFocus={(event) => event.preventDefault()}
      >
        {children}
        {/* Coloured to the panel, not to a token of its own — `bg-popover`
            here would leave a white pip on a dark panel. */}
        <PopoverArrow className="bg-foreground fill-foreground" />
      </PopoverContent>
    </Popover>
  );
}
