import { useCallback, useState } from "react";
import { apiMessage } from "@/lib/api/error-message";

export function useConfirmAction() {
  const [target, setTarget] = useState(null);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);

  const open = useCallback((next) => {
    setTarget(next);
    setError(null);
  }, []);

  const close = useCallback(() => {
    setTarget(null);
    setError(null);
  }, []);

  // Can't close while the request is still running.
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
        await onDone?.(result);
        close();
        return true;
      } catch (cause) {
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
