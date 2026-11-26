"use client";

import { useCallback, useEffect, useRef, useState } from "react";

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

  // Radix focuses the content on open, which blurs the trigger and runs this.
  const closeOnLeave = useCallback(() => {
    if (!canHover()) return;
    clearTimeout(openTimer.current);
    if (!hoverOpened.current) return;
    closeTimer.current = setTimeout(() => setOpen(false), 120);
  }, []);

  const openOnKeyboardFocus = useCallback(
    (event) => {
      if (!focusOpens) return;
      if (!event.currentTarget.matches(":focus-visible")) return;
      openOnHover();
    },
    [focusOpens, openOnHover],
  );

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
    contentProps: {
      onMouseEnter: openOnHover,
      onMouseLeave: closeOnLeave,
    },
  };
}
