"use client";

import { useCallback, useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { apiMessage } from "@/lib/api/error-message";

// `key` names the busy row in lists. Errors are reported, never rethrown;
// `run` resolves to `true` or `false`.
export function useAction() {
  const { refreshAndWait } = useRefresh();
  // The key of the work in flight, or null, so a list can tell WHICH row is busy.
  const [pendingKey, setPendingKey] = useState(null);

  const run = useCallback(
    async (fn, { success, error, refresh = false, onSuccess, key = true } = {}) => {
      setPendingKey(key);
      try {
        const result = await fn();
        // Re-read first, then toast and close, or the old row stays on screen.
        if (refresh) await refreshAndWait();
        if (success) toast.success(success);
        await onSuccess?.(result);
        return true;
      } catch (cause) {
        toast.error(apiMessage(cause, error));
        return false;
      } finally {
        setPendingKey(null);
      }
    },
    [refreshAndWait],
  );

  return {
    run,
    pending: pendingKey !== null,
    pendingKey,
    /** For a row in a list: `isPending(row.id)`. */
    isPending: useCallback((key) => pendingKey !== null && pendingKey === key, [pendingKey]),
  };
}
