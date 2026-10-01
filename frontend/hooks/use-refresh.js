"use client";

import { useEffect, useRef, useTransition } from "react";
import { useRouter } from "next/navigation";
import { useNavTransition } from "@/components/data-table/nav-transition";

// A bare `router.refresh()` returns immediately. `refreshThen(after)` runs `after` once
// the refreshed page is on screen, even if the refresh unmounts the caller.
export function useRefresh() {
  const nav = useNavTransition();
  const router = useRouter();
  const [localPending, startLocal] = useTransition();
  // Its own transition: under a NavTransitionProvider `pending` would never see leaving.
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

  /* The refresh can unmount the caller (e.g. a deleted row's dialog), so flush waiters on unmount. */
  useEffect(() => () => flush(), []);

  return {
    pending,
    refresh,
    refreshThen: (fn) => {
      wait(fn);
      refresh();
    },
    // Await before the success toast and close, so a dialog never uncovers stale state.
    refreshAndWait: () =>
      new Promise((resolve) => {
        wait(resolve);
        refresh();
      }),
    /* Resolves once the new page is on screen; an unmount is covered by the flush above. */
    pushAndWait: (href) =>
      new Promise((resolve) => {
        wait(resolve);
        startLeaving(() => router.push(href));
      }),
    /* For when the last row of a page leaves it, avoiding a server redirect. */
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
