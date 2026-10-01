import * as React from "react";

// Radix only restores focus to its own Trigger. The opener is read on first render,
// before an `autoFocus` input inside can take it.
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

// A menu item disappears with its menu, so focus goes to the menu's trigger (Radix labels the menu with its id).
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
