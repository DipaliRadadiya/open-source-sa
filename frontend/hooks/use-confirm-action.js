import { useCallback, useState } from "react";
import { apiMessage } from "@/lib/api/error-message";

/**
 * The state a confirmation dialog needs: what it is confirming, whether the
 * write is in flight, and why the last attempt failed. On failure the dialog
 * stays open (keeping retry and context) and shows the error.
 *
 *   const remove = useConfirmAction();
 *   ...
 *   <ConfirmDialog
 *     open={remove.isOpen}
 *     onOpenChange={remove.setOpen}
 *     pending={remove.pending}
 *     error={remove.error}
 *     onConfirm={() => remove.run(() => deleteThing(remove.target.id), { onDone })}
 *   />
 */
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

  /*
   * Radix calls this for Escape, the backdrop and Cancel alike, so one guard
   * covers every way out. Refused while the write is in flight: closing then
   * would leave the request running with nothing on screen owning it.
   */
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
