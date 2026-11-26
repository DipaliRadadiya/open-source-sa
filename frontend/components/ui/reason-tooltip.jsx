import { createContext, useContext, useEffect, useState } from "react";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

const DisabledReasonContext = createContext(null);

export const DENIED = "__denied__";

export function readOnlyReason(reason) {
  return reason === DENIED ? null : reason;
}

export function useDisabledReason() {
  return useContext(DisabledReasonContext);
}

// A control's own disabledReason wins. denied: buttons are hidden, other controls show their value.
export function DisabledReasonProvider({ reason, denied = false, children }) {
  return (
    <DisabledReasonContext.Provider
      value={denied ? { reason: null, handled: false, denied: true } : reason ? { reason, handled: false } : null}
    >
      {children}
    </DisabledReasonContext.Provider>
  );
}

// Disabled buttons don't fire pointer events, so the span carries them. Popover on touch.
export function ReasonTooltip({ reason, children, className = "inline-flex" }) {
  const coarse = useCoarsePointer();

  if (reason === DENIED) return null;
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
    <Tooltip delayDuration={0}>
      <TooltipTrigger asChild>
        <span tabIndex={0} className={className}>
          {children}
        </span>
      </TooltipTrigger>
      <TooltipContent className="max-w-60">{reason}</TooltipContent>
    </Tooltip>
  );

  return (
    <DisabledReasonContext.Provider value={{ reason, handled: true }}>
      {content}
    </DisabledReasonContext.Provider>
  );
}

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
