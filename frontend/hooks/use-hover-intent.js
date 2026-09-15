import * as React from "react";

/**
 * Hover, but only when the pointer means it.
 *
 * A bare `onMouseEnter` on a 48px rail that sits along the whole left edge of
 * the screen fires constantly: every trip from the page to the browser's back
 * button crosses it, and each crossing would throw a 256px panel open and shut.
 * The delay is what separates "I am going to the sidebar" from "I passed over
 * the sidebar".
 *
 * The two delays are deliberately different. Opening waits long enough to
 * ignore a pass-through; closing waits longer still, because the pointer
 * routinely leaves for a moment on its way between two items — and a panel that
 * snaps shut mid-reach is worse than one that lingers.
 *
 * ⚠️ Touch has no hover. A touch "hover" fires on tap and then sticks until the
 * next tap elsewhere, so on a tablet the panel would open on the first tap and
 * swallow the one the user actually meant. Gated on a real pointer.
 */
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

  // Dropping out of the enabled state — the user opened the sidebar with the
  // toggle, or switched to a touch device — must also drop the hover.
  //
  // Adjusted during render rather than in an effect. Not style: an effect would
  // leave `hovered` true for the frame in between, and more importantly it
  // would still be true the next time the rail collapsed — so the panel would
  // spring open on its own, with no pointer anywhere near it. React documents
  // this pattern for exactly this case.
  const [wasActive, setWasActive] = React.useState(active);

  if (wasActive !== active) {
    setWasActive(active);

    // State only — the pending timer is cancelled in the effect below, because
    // a ref must not be touched during render.
    if (!active) setHovered(false);
  }

  // Cancel a pending open/close when the hook goes inactive, and again on
  // unmount, so a timer cannot land on a sidebar that is already open or a
  // tree that is already gone.
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
        // `pointerenter` reports its own kind, which is more reliable than the
        // media query alone for hybrid machines: a touchscreen laptop matches
        // `pointer: fine` and still sends touch events.
        if (event.pointerType === "touch") return;
        schedule(true, enterDelay);
      },
      onPointerLeave: (event) => {
        if (event.pointerType === "touch") return;
        schedule(false, leaveDelay);
      },
      // Keyboard users never fire pointer events, so tabbing into the rail
      // would leave them reading a column of unlabelled icons. Focus opens it
      // immediately — there is no ambiguity to wait out.
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

/**
 * Whether this machine has a pointer that can hover at all.
 *
 * Matches the media query rather than sniffing the user agent, so a tablet with
 * a trackpad attached gets the behaviour and the same tablet without one does
 * not.
 */
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
