"use client";

import { useCallback, useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { apiMessage } from "@/lib/api/error-message";

export function useAction() {
  const { refreshAndWait } = useRefresh();
  const [pendingKey, setPendingKey] = useState(null);

  const run = useCallback(
    async (fn, { success, error, refresh = false, onSuccess, key = true } = {}) => {
      setPendingKey(key);
      try {
        const result = await fn();
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
    isPending: useCallback((key) => pendingKey !== null && pendingKey === key, [pendingKey]),
  };
}
