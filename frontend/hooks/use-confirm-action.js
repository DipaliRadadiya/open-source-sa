import { useCallback, useState } from "react";
import { apiMessage } from "@/lib/api/error-message";

// State for a confirmation dialog. On failure the dialog stays open with the error.
export function useConfirmAction() {
  const [target, setTarget] = useState(null);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);

  // Opening, closing and re-targeting all clear the last failure, so an error
  // never shows under a different row's name.
  const open = useCallback((next) => {
    setTarget(next);
    setError(null);
  }, []);

  const close = useCallback(() => {
    setTarget(null);
    setError(null);
  }, []);

  // Radix calls this for every way out. Refused while the write is in flight, or the
  // request would run with nothing on screen owning it.
  const setOpen = useCallback(
    (next) => {
      if (pending) return;
      if (!next) close();
    },
    [pending, close],
  );

  const run = useCallback(
    async (fn, { fallback, onDone } = {}) => {
      setPending(true);
      setError(null);
      try {
        const result = await fn();
        // onDone (where callers re-read the page) before closing, so the dialog
        // never uncovers stale state.
        await onDone?.(result);
        close();
        return true;
      } catch (cause) {
        // Shown in the dialog, not a toast.
        setError(apiMessage(cause, fallback));
        return false;
      } finally {
        setPending(false);
      }
    },
    [close],
  );

  return { target, isOpen: target !== null, open, close, setOpen, pending, error, run };
}
