import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";

// A disabled item is `pointer-events-none`, so the wrapping span receives the hover.
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
