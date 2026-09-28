import * as React from "react";

/**
 * Put focus back on whatever opened the dialog.
 *
 * Radix returns focus to the dialog's own Trigger and nothing else. Most
 * dialogs here are opened from state — a button elsewhere sets `open` — so
 * there is no Trigger, and closing one dropped focus on <body>: a keyboard
 * user was sent back to the top of the page.
 *
 * The opener is read while the content first renders, before anything inside
 * it can take focus — an `autoFocus` input moves it during commit, earlier
 * than Radix's own open-focus event, so reading it there found the input.
 *
 * Skipped when the caller handled it, and when that element is gone — Radix's
 * own behaviour applies then.
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
