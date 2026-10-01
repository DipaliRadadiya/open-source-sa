"use client";

import { useCallback, useEffect, useRef, useState } from "react";

// Opens on hover where it exists, yet stays reachable on touch. A hover-opened
// panel must not steal focus; a click- or keyboard-opened one must.
export function useHoverPopover({ openDelay = 0, focusOpens = false } = {}) {
  const [open, setOpen] = useState(false);
  const openTimer = useRef(null);
  const closeTimer = useRef(null);
  const hoverOpened = useRef(false);

  useEffect(
    () => () => {
      clearTimeout(openTimer.current);
      clearTimeout(closeTimer.current);
    },
    [],
  );

  const canHover = () =>
    typeof window !== "undefined" && window.matchMedia("(hover: hover)").matches;

  const openOnHover = useCallback(() => {
    if (!canHover()) return;
    clearTimeout(closeTimer.current);
    if (open) return;
    clearTimeout(openTimer.current);
    openTimer.current = setTimeout(() => {
      hoverOpened.current = true;
      setOpen(true);
    }, openDelay);
  }, [open, openDelay]);

  /* Only hover-opened: Radix focuses the content on open, which blurs the trigger and runs this. */
  const closeOnLeave = useCallback(() => {
    if (!canHover()) return;
    clearTimeout(openTimer.current);
    if (!hoverOpened.current) return;
    closeTimer.current = setTimeout(() => setOpen(false), 120);
  }, []);

  /* Only a keyboard Tab (`:focus-visible`), never a dialog's initial focus. */
  const openOnKeyboardFocus = useCallback(
    (event) => {
      if (!focusOpens) return;
      if (!event.currentTarget.matches(":focus-visible")) return;
      openOnHover();
    },
    [focusOpens, openOnHover],
  );

  // Radix calls this only for its own interactions, never hover.
  const onOpenChange = useCallback((next) => {
    clearTimeout(openTimer.current);
    clearTimeout(closeTimer.current);
    hoverOpened.current = false;
    setOpen(next);
  }, []);

  return {
    open,
    onOpenChange,
    hoverOpened,
    triggerProps: {
      onMouseEnter: openOnHover,
      onMouseLeave: closeOnLeave,
      onFocus: openOnKeyboardFocus,
      onBlur: closeOnLeave,
    },
    // Keep the panel open while the pointer moves from the trigger onto it.
    contentProps: {
      onMouseEnter: openOnHover,
      onMouseLeave: closeOnLeave,
    },
  };
}
