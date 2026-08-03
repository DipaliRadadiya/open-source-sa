"use client";

import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

/**
 * Wraps a control that may be disabled, and says why when it is.
 *
 * A disabled button with no explanation is a dead end — the user can see what
 * they want and not what is in the way. The span carries the pointer and focus
 * events because a disabled button fires neither, which is also why it only
 * becomes focusable while there is something to read.
 *
 * Pass `reason={null}` when the control is enabled: no wrapper behaviour, no
 * tooltip.
 */
export function ReasonTooltip({ reason, children, className = "inline-flex" }) {
  if (!reason) return children;

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span tabIndex={0} className={className}>
          {children}
        </span>
      </TooltipTrigger>
      <TooltipContent className="max-w-60">{reason}</TooltipContent>
    </Tooltip>
  );
}
