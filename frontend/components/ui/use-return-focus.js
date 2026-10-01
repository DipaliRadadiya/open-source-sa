import * as React from "react";

/**
 * Returns focus to whatever opened the dialog. Radix only restores focus to
 * its own Trigger, and most dialogs here open from state.
 *
 * The opener is read on first render, before an `autoFocus` input inside can
 * take focus. Skipped when the caller handled it or the element is gone.
 */
export function useReturnFocus(onCloseAutoFocus) {
  const opener = React.useRef(null);

  const onClose = React.useCallback(
    (event) => {
      onCloseAutoFocus?.(event);
      const target = opener.current;
      opener.current = null;
      if (event.defaultPrevented) return;
      if (!target || target === document.body || !target.isConnected) return;
      event.preventDefault();
      target.focus();
    },
    [onCloseAutoFocus],
  );

  const capture = React.useCallback((element) => {
    opener.current = menuTriggerOf(element) ?? element;
  }, []);

  return { capture, onCloseAutoFocus: onClose };
}

/*
 * A dialog opened from a menu item is opened by an element that is about to
 * disappear with its menu, so hand focus to the button that opened the menu.
 * Radix labels the menu with its trigger's id, which is still in the page.
 */
function menuTriggerOf(element) {
  const menu = element?.closest?.('[role="menu"]');
  const id = menu?.getAttribute("aria-labelledby");
  return id ? document.getElementById(id) : null;
}

/** Rendered first inside the content, so it mounts once per opening. */
export function OpenerCapture({ onCapture }) {
  React.useState(() => {
    if (typeof document !== "undefined") onCapture(document.activeElement);
  });
  return null;
}
