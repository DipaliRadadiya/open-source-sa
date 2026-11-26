"use client";

import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";

export function HoverHint({ content, side, children }) {
  if (content === null || content === undefined || content === false || content === "") return children;

  return (
    <TooltipProvider delayDuration={300}>
      <Tooltip>
        <TooltipTrigger asChild>{children}</TooltipTrigger>
        <TooltipContent side={side} className="max-w-80 break-words">
          {content}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  );
}
