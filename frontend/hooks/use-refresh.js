"use client";

import { useEffect, useRef, useTransition } from "react";
import { useRouter } from "next/navigation";
import { useNavTransition } from "@/components/data-table/nav-transition";

/**
 * Re-run the server component, and know while it is happening.
 *
 * Five buttons across the panel called `router.refresh()` straight from an
 * onClick. That works — but a bare call returns immediately and the render
 * happens later, so the button did nothing visible for the whole round trip.
 * On the Sync page, where a scan can take seconds, it read as broken: you
 * pressed refresh and the screen sat there.
 *
 * The logic is shared rather than the markup. Those five buttons are a ghost
 * one with a label, an outline one, a small one, a full-width one, and one
 * with no icon at all — folding them into a single component would take five
 * props to describe differences that are all deliberate. What they actually
 * had in common was this, and only this.
 *
 * Under a `<NavTransitionProvider>` it borrows the list's pending signal, so
 * the table dims with the same transition rather than running a second one
 * beside it; elsewhere it keeps its own.
 *
 * `refreshThen(after)` runs `after` once the refreshed page is on screen. A
 * dialog that closed on the API's answer and refreshed behind it left the old
 * list up for 1.5–4 s on a real server, under a toast saying it was done — a
 * renamed file still wearing its old name. Keep the spinner while `pending`
 * and do the toast and the close in `after`. It still runs if the refresh
 * unmounts the caller.
 */
export function useRefresh() {
  const nav = useNavTransition();
  const router = useRouter();
  const [localPending, startLocal] = useTransition();

  const pending = nav ? nav.isPending : localPending;
  const refresh = nav ? nav.refresh : () => startLocal(() => router.refresh());
  const after = useRef(null);

  useEffect(() => {
    if (pending || !after.current) return;
    const run = after.current;
    after.current = null;
    run();
  }, [pending]);

  /*
   * The refresh can remove the very component that asked for it: a deleted
   * worker's row takes its own delete dialog with it. The effect above then
   * never sees `pending` fall, and "Worker deleted." was never shown. Run the
   * waiting step on the way out instead — by then the refresh has landed.
   */
  useEffect(
    () => () => {
      const run = after.current;
      after.current = null;
      run?.();
    },
    [],
  );

  return {
    pending,
    refresh,
    refreshThen: (fn) => {
      after.current = fn;
      refresh();
    },
  };
}
