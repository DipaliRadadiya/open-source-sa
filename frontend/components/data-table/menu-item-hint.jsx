import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { DENIED } from "@/components/ui/reason-tooltip";

export function MenuItemHint({ hint, side = "left", children }) {
  if (hint === DENIED) return null;
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
