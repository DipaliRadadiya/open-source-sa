"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from "react";

/**
 * Knows whether any settings card on the page has edits that were never saved.
 *
 * Each card saves on its own, so nothing else on the page can tell. Without
 * this, typing a new hostname and then clicking another tab throws the edit
 * away silently — no warning, no trace, and the field is back to its old value
 * when you return.
 */
const UnsavedContext = createContext(null);

export function UnsavedProvider({ children }) {
  // A set of ids rather than a boolean: two cards can be dirty at once, and one
  // of them saving must not clear the warning for the other.
  const [dirty, setDirty] = useState(() => new Set());

  const setSectionDirty = useCallback((id, isDirty) => {
    setDirty((prev) => {
      if (isDirty === prev.has(id)) return prev;
      const next = new Set(prev);
      if (isDirty) next.add(id);
      else next.delete(id);
      return next;
    });
  }, []);

  const value = useMemo(
    () => ({ hasUnsaved: dirty.size > 0, setSectionDirty }),
    [dirty, setSectionDirty],
  );

  // Covers the browser's own exits — reload, close, back out of the app — which
  // no in-app handler can intercept.
  useEffect(() => {
    if (!value.hasUnsaved) return;
    const onBeforeUnload = (event) => {
      event.preventDefault();
      // Chrome ignores the string and shows its own wording, but still needs
      // returnValue set for the prompt to appear at all.
      event.returnValue = "";
    };
    window.addEventListener("beforeunload", onBeforeUnload);
    return () => window.removeEventListener("beforeunload", onBeforeUnload);
  }, [value.hasUnsaved]);

  return (
    <UnsavedContext.Provider value={value}>{children}</UnsavedContext.Provider>
  );
}

export function useUnsaved() {
  // Null outside the settings layout: the tab strip is the only consumer, but a
  // missing provider should degrade to "nothing to lose" rather than throw.
  return (
    useContext(UnsavedContext) ?? {
      hasUnsaved: false,
      setSectionDirty: () => {},
    }
  );
}

/** Registers one card's dirty state, and clears it when the card unmounts. */
export function useWatchUnsaved(id, isDirty) {
  const { setSectionDirty } = useUnsaved();

  useEffect(() => {
    setSectionDirty(id, isDirty);
    return () => setSectionDirty(id, false);
  }, [id, isDirty, setSectionDirty]);
}
