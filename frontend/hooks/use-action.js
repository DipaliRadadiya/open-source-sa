"use client";

import { useCallback, useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { apiMessage } from "@/lib/api/error-message";

/**
 * Run one API call and know, on screen, that it is running.
 *
 * Seventy handlers across sixty-one components were written out longhand: raise
 * a flag, try, call, toast, catch, `apiMessage`, finally lower the flag. Not a
 * style problem — fourteen of them raised the flag and then rendered nothing
 * for it, so the button greyed out and the click read as ignored. Every one of
 * those was somebody re-deriving the same six lines and dropping one.
 *
 * Shaped from what those handlers actually do, counted rather than guessed:
 * 89% show a success toast, 73% call `router.refresh()`, 26% close a dialog.
 * So the toast and the refresh are options here, and anything else goes in
 * `onSuccess`.
 *
 * `key` is for lists. Pass the row's id and `pendingKey` names the row being
 * worked on, so one row spins instead of all of them — the shape the backups
 * and sync lists already hand-rolled as `busyId`.
 *
 *   const { run, pending } = useAction();
 *   run(() => deleteThing(id), { success: t("deleted"), error: t("deleteFailed"), refresh: true })
 *
 * Errors are reported, never rethrown: every one of the seventy ended in a
 * toast, and a rejected promise escaping a click handler is an unhandled
 * rejection nobody sees. `run` resolves to `true` or `false` so a caller can
 * branch on the outcome — closing a dialog only when it worked, say.
 */
export function useAction() {
  const router = useRouter();
  // The key of the work in flight, or null. A plain boolean cannot say WHICH
  // row is busy, and a list that disables every row while one of them saves is
  // the same silence in a different costume.
  const [pendingKey, setPendingKey] = useState(null);

  const run = useCallback(
    async (fn, { success, error, refresh = false, onSuccess, key = true } = {}) => {
      setPendingKey(key);
      try {
        const result = await fn();
        if (success) toast.success(success);
        await onSuccess?.(result);
        if (refresh) router.refresh();
        return true;
      } catch (cause) {
        toast.error(apiMessage(cause, error));
        return false;
      } finally {
        setPendingKey(null);
      }
    },
    [router],
  );

  return {
    run,
    pending: pendingKey !== null,
    pendingKey,
    /** For a row in a list: `isPending(row.id)`. */
    isPending: useCallback((key) => pendingKey !== null && pendingKey === key, [pendingKey]),
  };
}
