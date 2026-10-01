"use client";

import { useCallback, useEffect, useRef, useState } from "react";

/**
 * A Popover that also opens on hover where hovering exists (`(hover: hover)`),
 * without becoming unreachable on touch as a Radix Tooltip would.
 *
 *   - The close is on a timer, cancelled when the pointer reaches the panel,
 *     so crossing the gap from trigger to content does not close it.
 *   - `hoverOpened` records how it opened: a hover-opened panel must not steal
 *     focus; a click- or keyboard-opened one must hand focus over.
 *
 * `openDelay` is for large panels, so passing over a chip does not flash one.
 */
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

  /*
   * Only a hover-opened panel closes on leave. Without the guard a click-opened
   * panel closed itself: Radix focuses the content on open, which blurs the
   * trigger and runs this.
   */
  const closeOnLeave = useCallback(() => {
    if (!canHover()) return;
    clearTimeout(openTimer.current);
    if (!hoverOpened.current) return;
    closeTimer.current = setTimeout(() => setOpen(false), 120);
  }, []);

  /*
   * Only a keyboard Tab (`:focus-visible`), never the focus a dialog hands to
   * its first focusable element.
   */
  const openOnKeyboardFocus = useCallback(
    (event) => {
      if (!focusOpens) return;
      if (!event.currentTarget.matches(":focus-visible")) return;
      openOnHover();
    },
    [focusOpens, openOnHover],
  );

  // Radix calls this only for its own interactions (trigger click, Escape,
  // outside click), never for hover, so it reliably means "not hover".
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
