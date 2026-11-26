import * as React from "react";

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

  const [wasActive, setWasActive] = React.useState(active);

  if (wasActive !== active) {
    setWasActive(active);

    // Clear the timer in the effect below; refs can't be touched during render.
    if (!active) setHovered(false);
  }

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
        if (event.pointerType === "touch") return;
        schedule(true, enterDelay);
      },
      onPointerLeave: (event) => {
        if (event.pointerType === "touch") return;
        schedule(false, leaveDelay);
      },
      onFocusCapture: () => {
        if (!active) return;
        clear();
        setHovered(true);
      },
      onBlurCapture: (event) => {
        if (!active) return;
        if (event.currentTarget.contains(event.relatedTarget)) return;
        clear();
        setHovered(false);
      },
    }),
    [active, clear, enterDelay, leaveDelay, schedule],
  );

  return { hovered: active && hovered, handlers };
}

function useHasFinePointer() {
  const [fine, setFine] = React.useState(false);

  React.useEffect(() => {
    const mql = window.matchMedia("(hover: hover) and (pointer: fine)");
    const onChange = () => setFine(mql.matches);

    mql.addEventListener("change", onChange);
    const id = requestAnimationFrame(onChange);

    return () => {
      cancelAnimationFrame(id);
      mql.removeEventListener("change", onChange);
    };
  }, []);

  return fine;
}
