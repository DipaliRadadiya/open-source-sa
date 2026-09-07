import { useCallback, useState } from "react";
import { apiMessage } from "@/lib/api/error-message";

/**
 * The state a confirmation dialog needs: what it is confirming, whether the
 * write is in flight, and why the last attempt failed.
 *
 * Written because ~30 dialogs hand-rolled the first two and skipped the third.
 * Each one closed itself inside the `try` and only toasted in the `catch`, so a
 * failure left the same box on screen with no reason in it — a toast four
 * seconds from disappearing was the entire account of what went wrong. The
 * reports for this all say "the modal does not close".
 *
 * Staying open on failure is right: it keeps the retry and the context. What
 * was missing is the dialog admitting that is what it is doing.
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

  // Opening, closing and re-targeting all clear the last failure. A stale
  // error under a different row's name is worse than none.
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
        close();
        await onDone?.(result);
        return true;
      } catch (cause) {
        // Into the dialog, not a toast: it is the answer to a question the
        // reader asked one second ago, and it belongs where they asked it.
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
