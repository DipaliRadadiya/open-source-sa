import * as React from "react";

// A delay filters out pointers crossing the rail; closing waits longer so moving between items does not snap shut.
// Gated on a fine pointer: on touch a tap "hovers" and sticks.
export function useHoverIntent({ enterDelay = 120, leaveDelay = 260, enabled = true } = {}) {
  const [hovered, setHovered] = React.useState(false);
  const timer = React.useRef(null);
  const canHover = useHasFinePointer();

  const clear = React.useCallback(() => {
    if (timer.current !== null) {
      clearTimeout(timer.current);
      timer.current = null;
    }
  }, []);

  const active = enabled && canHover;

  // Leaving the enabled state drops the hover, during render so a stale `hovered`
  // cannot reopen the panel the next time the rail collapses.
  const [wasActive, setWasActive] = React.useState(active);

  if (wasActive !== active) {
    setWasActive(active);

    // State only — the pending timer is cancelled in the effect below, because
    // a ref must not be touched during render.
    if (!active) setHovered(false);
  }

  // Cancel pending timers when inactive and on unmount, so none lands on an open sidebar or a gone tree.
  React.useEffect(() => {
    if (!active) clear();

    return clear;
  }, [active, clear]);

  const schedule = React.useCallback(
    (next, delay) => {
      if (!active) return;
      clear();
      timer.current = setTimeout(() => {
        timer.current = null;
        setHovered(next);
      }, delay);
    },
    [active, clear],
  );

  const handlers = React.useMemo(
    () => ({
      onPointerEnter: (event) => {
        // `pointerType` is more reliable than the media query on hybrid
        // machines: a touchscreen laptop matches `pointer: fine` but sends touch.
        if (event.pointerType === "touch") return;
        schedule(true, enterDelay);
      },
      onPointerLeave: (event) => {
        if (event.pointerType === "touch") return;
        schedule(false, leaveDelay);
      },
      // Keyboard users fire no pointer events, so focus opens it immediately.
      onFocusCapture: () => {
        if (!active) return;
        clear();
        setHovered(true);
      },
      onBlurCapture: (event) => {
        if (!active) return;
        // Only when focus actually left the subtree; moving between two items
        // inside it fires blur too.
        if (event.currentTarget.contains(event.relatedTarget)) return;
        clear();
        setHovered(false);
      },
    }),
    [active, clear, enterDelay, leaveDelay, schedule],
  );

  return { hovered: active && hovered, handlers };
}

/** Whether this machine has a pointer that can hover, via media query, not UA sniffing. */
function useHasFinePointer() {
  const [fine, setFine] = React.useState(false);

  React.useEffect(() => {
    const mql = window.matchMedia("(hover: hover) and (pointer: fine)");
    const onChange = () => setFine(mql.matches);

    mql.addEventListener("change", onChange);
    // Initial value through the same callback the listener uses, kept out of
    // the effect body to satisfy react-hooks/set-state-in-effect.
    const id = requestAnimationFrame(onChange);

    return () => {
      cancelAnimationFrame(id);
      mql.removeEventListener("change", onChange);
    };
  }, []);

  return fine;
}
