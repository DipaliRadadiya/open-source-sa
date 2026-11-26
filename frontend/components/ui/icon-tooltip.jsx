import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";

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
        <span className={className}>{children}</span>
      </TooltipTrigger>
      <TooltipContent>{label}</TooltipContent>
    </Tooltip>
  );
}
