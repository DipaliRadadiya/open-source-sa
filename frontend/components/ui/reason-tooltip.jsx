import { createContext, useContext, useEffect, useState } from "react";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

/**
 * Two different things a parent can say about why its children are disabled:
 *
 * - `handled: true`  — "I am already showing a tooltip over this area", so a
 *   nested control must stay silent or the reader gets two overlapping bubbles.
 * - `handled: false` — "here is the reason; use it as your own if you have
 *   nothing more specific". This is what a whole permission-gated form sets, so
 *   its controls explain themselves without every one of them repeating the
 *   prop.
 */
const DisabledReasonContext = createContext(null);

export function useDisabledReason() {
  return useContext(DisabledReasonContext);
}

/**
 * Supplies one reason to every disabled control beneath it.
 *
 * For the common case where a whole card or form is switched off by a single
 * condition — usually a missing permission. Without it each control falls back
 * to a generic line that says nothing, and with it they all say the same true
 * thing. A control that knows better still wins: `disabledReason` takes
 * precedence over whatever is inherited.
 */
export function DisabledReasonProvider({ reason, children }) {
  return (
    <DisabledReasonContext.Provider
      value={reason ? { reason, handled: false } : null}
    >
      {children}
    </DisabledReasonContext.Provider>
  );
}

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
 *
 * On a touch screen this renders a Popover instead. A Radix tooltip opens on
 * hover and focus, and a finger produces neither — so on a phone every
 * disabled control in the panel was mute, which is the exact dead end this
 * component exists to prevent. Same wrapper, same reason text, opened by tap.
 */
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
    <Tooltip>
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

/**
 * Whether the primary pointer cannot hover.
 *
 * Resolved after mount, never during render: the server has no pointer, and
 * branching on one before hydration would mismatch. Starting false means the
 * desktop path is what renders first, which is the common case and the one
 * that was already correct.
 */
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
