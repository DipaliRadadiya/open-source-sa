import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";

/**
 * Says what an icon-only button does.
 *
 * An unlabelled glyph is a guess, and `aria-label` only rescues the people
 * using a screen reader — a sighted person hovering gets nothing. This is the
 * one thing the panel had no shared component for, so every screen re-decided:
 * some wrapped a raw `Tooltip`, one file grew a private copy of this, and
 * several just shipped the icon.
 *
 * `reason` takes over when the button is disabled, because "why can I not use
 * this" beats "what does this do" — that is the whole job of {@link ReasonTooltip},
 * so it is handed over rather than reimplemented, and the touch-screen Popover
 * path comes with it.
 *
 * The span carries the hover: a disabled button fires neither pointer nor
 * focus events, so a tooltip attached to the button itself goes quiet exactly
 * when it has the most to say.
 */
export function IconTooltip({ label, reason = null, children, className = "inline-flex" }) {
  if (reason) {
    return (
      <ReasonTooltip reason={reason} className={className}>
        {children}
      </ReasonTooltip>
    );
  }

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        {/* No tabIndex: the button inside is enabled here, so it is already a
            tab stop, and a second one would make every icon take two presses
            to walk past. */}
        <span className={className}>{children}</span>
      </TooltipTrigger>
      <TooltipContent>{label}</TooltipContent>
    </Tooltip>
  );
}
