"use client";

import { useCallback, useEffect, useRef, useState } from "react";

/**
 * A Popover that also opens on hover — on the devices where hovering is a
 * thing, and without becoming unreachable on the ones where it is not.
 *
 * A Radix Tooltip would give the hover for free and then strand every touch
 * user, because tooltips are hover- and focus-only and dismiss themselves on
 * pointer-down. So the panel uses Popovers and adds the hover back here, gated
 * on `(hover: hover)` so a tap on a phone opens it once rather than twice.
 *
 * Two details are load-bearing and were both found the hard way:
 *
 *   - the close is on a timer, cancelled when the pointer reaches the panel.
 *     Without it, crossing the few pixels between the trigger and its own
 *     content closes the thing you were moving towards.
 *   - `hoverOpened` records HOW it opened. A panel opened by hover must not
 *     steal focus; one opened by a click or the keyboard must hand focus over,
 *     or the links inside are unreachable without a mouse.
 *
 * `openDelay` exists for triggers whose panel is large: opening a 350px card
 * the instant a pointer crosses a chip makes a dashboard feel twitchy. Small
 * notes want 0.
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
   * Only a panel the pointer SUMMONED closes itself when the pointer goes.
   *
   * A click asks for something and expects it to stay; a hover is a glance.
   * Without the `hoverOpened` guard a click-opened panel died ~120ms later,
   * because Radix hands focus to the content on open, which blurs the trigger,
   * which runs this — the panel closing itself in response to its own opening.
   */
  const closeOnLeave = useCallback(() => {
    if (!canHover()) return;
    clearTimeout(openTimer.current);
    if (!hoverOpened.current) return;
    closeTimer.current = setTimeout(() => setOpen(false), 120);
  }, []);

  /*
   * Only a keyboard Tab, never the focus a dialog hands to the first thing it
   * can reach — that opened notes over the control underneath on every dialog
   * whose first label carries one. `:focus-visible` is the distinction the
   * browser already draws.
   */
  const openOnKeyboardFocus = useCallback(
    (event) => {
      if (!focusOpens) return;
      if (!event.currentTarget.matches(":focus-visible")) return;
      openOnHover();
    },
    [focusOpens, openOnHover],
  );

  // Radix calls this for its OWN interactions only — a click on the trigger,
  // Escape, a click outside — never for the hover above, which sets state
  // directly. That is exactly what makes it a reliable "this was not hover".
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
    // Without these the panel is unreadable with a mouse: it would close the
    // moment the pointer left the trigger to reach it.
    contentProps: {
      onMouseEnter: openOnHover,
      onMouseLeave: closeOnLeave,
    },
  };
}
