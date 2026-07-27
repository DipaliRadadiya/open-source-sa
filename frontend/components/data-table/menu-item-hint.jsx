"use client";

import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";

/**
 * Wraps a menu item (typically a disabled one) so hovering explains *why* it's
 * disabled. A disabled item is `pointer-events-none`, so the wrapping span is
 * what receives the hover. When `hint` is falsy the child renders unchanged.
 */
export function MenuItemHint({ hint, side = "left", children }) {
  if (!hint) return children;
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="block">{children}</span>
      </TooltipTrigger>
      <TooltipContent side={side}>{hint}</TooltipContent>
    </Tooltip>
  );
}
