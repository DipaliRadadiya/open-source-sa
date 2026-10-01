import { createContext, useContext, useEffect, useState } from "react";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

// `handled: true`: a parent already shows a tooltip, so nested controls stay silent.
// `handled: false`: a fallback reason for controls with nothing more specific.
const DisabledReasonContext = createContext(null);

export function useDisabledReason() {
  return useContext(DisabledReasonContext);
}

/** A control's own `disabledReason` takes precedence over this one. */
export function DisabledReasonProvider({ reason, children }) {
  return (
    <DisabledReasonContext.Provider
      value={reason ? { reason, handled: false } : null}
    >
      {children}
    </DisabledReasonContext.Provider>
  );
}

// The span carries pointer and focus events, which a disabled button does not fire.
// Popover on touch screens: Radix tooltips never open on touch.
export function ReasonTooltip({ reason, children, className = "inline-flex" }) {
  const coarse = useCoarsePointer();

  if (!reason) return children;

  const content = coarse ? (
    <Popover>
      <PopoverTrigger asChild>
        <span tabIndex={0} className={className}>
          {children}
        </span>
      </PopoverTrigger>
      <PopoverContent side="top" className="w-auto max-w-60 px-3 py-2 text-sm">
        {reason}
      </PopoverContent>
    </Popover>
  ) : (
    /* No delay (the shell default is 300ms): this answers "why can't I press this". */
    <Tooltip delayDuration={0}>
      <TooltipTrigger asChild>
        <span tabIndex={0} className={className}>
          {children}
        </span>
      </TooltipTrigger>
      <TooltipContent className="max-w-60">{reason}</TooltipContent>
    </Tooltip>
  );

  // A parent may already provide a precise reason. Let nested primitives detect
  // that context and avoid rendering a second tooltip with the generic fallback.
  return (
    <DisabledReasonContext.Provider value={{ reason, handled: true }}>
      {content}
    </DisabledReasonContext.Provider>
  );
}

/** Resolved after mount to avoid a hydration mismatch; the desktop path renders first. */
function useCoarsePointer() {
  const [coarse, setCoarse] = useState(false);

  useEffect(() => {
    const query = window.matchMedia("(hover: none)");
    const sync = () => setCoarse(query.matches);

    sync();
    query.addEventListener("change", sync);
    return () => query.removeEventListener("change", sync);
  }, []);

  return coarse;
}
