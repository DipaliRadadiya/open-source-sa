"use client";

import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";

// The panel's tooltip in place of a native `title` (Krishna, 4 Nov: the browser's own bubble looked
// out of place). Adds no element: the trigger is the child itself. Its own provider, so it also
// works on the setup and sign-in pages, whose layouts have none.
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
