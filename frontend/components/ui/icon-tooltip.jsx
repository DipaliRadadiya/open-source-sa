import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";

// When disabled, `reason` takes over; the span carries the hover since a disabled button fires none.
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
