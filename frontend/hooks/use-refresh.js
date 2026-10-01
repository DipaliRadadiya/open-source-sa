"use client";

import { useEffect, useRef, useTransition } from "react";
import { useRouter } from "next/navigation";
import { useNavTransition } from "@/components/data-table/nav-transition";

/**
 * Re-run the server component and expose `pending` while it happens, since a
 * bare `router.refresh()` returns immediately. Under a
 * `<NavTransitionProvider>` it shares the list's pending signal.
 *
 * `refreshThen(after)` runs `after` once the refreshed page is on screen: keep
 * the spinner while `pending` and toast/close in `after`, so a dialog never
 * uncovers stale data. It still runs if the refresh unmounts the caller.
 */
export function useRefresh() {
  const nav = useNavTransition();
  const router = useRouter();
  const [localPending, startLocal] = useTransition();
  // Its own transition: leaving the page is not the list's refresh, and under
  // a NavTransitionProvider `pending` below would never see it.
  const [leaving, startLeaving] = useTransition();

  const pending = nav ? nav.isPending : localPending;
  const refresh = nav ? nav.refresh : () => startLocal(() => router.refresh());
  // A queue, not a slot: two waiters on one hook must both run.
  const after = useRef([]);
  const wait = (fn) => {
    after.current.push(fn);
  };
  const flush = () => {
    const waiting = after.current;
    after.current = [];
    waiting.forEach((run) => run());
  };

  useEffect(() => {
    if (pending || leaving || after.current.length === 0) return;
    flush();
  }, [pending, leaving]);

  /*
   * The refresh can unmount the component that asked for it (e.g. a deleted
   * row's dialog), so run pending waiters on unmount; the refresh has landed by then.
   */
  useEffect(() => () => flush(), []);

  return {
    pending,
    refresh,
    refreshThen: (fn) => {
      wait(fn);
      refresh();
    },
    // The same, awaitable: `await refreshAndWait()` before the success toast
    // and the close, so a dialog never uncovers the state it just changed.
    refreshAndWait: () =>
      new Promise((resolve) => {
        wait(resolve);
        refresh();
      }),
    /*
     * Navigate and resolve once the new page is on screen. Keep the dialog and
     * its spinner up until then (e.g. after deleting the page's own resource);
     * an unmount is covered by the flush above.
     */
    pushAndWait: (href) =>
      new Promise((resolve) => {
        wait(resolve);
        startLeaving(() => router.push(href));
      }),
    /*
     * Same, but lands on a different page of the list, e.g. when the last row
     * of a page leaves it, avoiding a server redirect.
     */
    navigateThen: (updates, fn) => {
      if (!nav) {
        refresh();
        fn();
        return;
      }
      wait(fn);
      nav.setQuery(updates);
    },
  };
}
