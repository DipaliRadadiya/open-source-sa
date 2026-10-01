import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";

/**
 * Tooltip naming what an icon-only button does. When disabled, `reason` takes
 * over via {@link ReasonTooltip} (including its touch Popover). The span
 * carries the hover because a disabled button fires no pointer events.
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
        {/* No tabIndex: the enabled button inside is already a tab stop. */}
        <span className={className}>{children}</span>
      </TooltipTrigger>
      <TooltipContent>{label}</TooltipContent>
    </Tooltip>
  );
}
