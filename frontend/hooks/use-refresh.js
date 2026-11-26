"use client";

import { useEffect, useRef, useTransition } from "react";
import { useRouter } from "next/navigation";
import { useNavTransition } from "@/components/data-table/nav-transition";

export function useRefresh() {
  const nav = useNavTransition();
  const router = useRouter();
  const [localPending, startLocal] = useTransition();
  const [leaving, startLeaving] = useTransition();

  const pending = nav ? nav.isPending : localPending;
  const refresh = nav ? nav.refresh : () => startLocal(() => router.refresh());
  // A queue, so two callers waiting on the same hook both run.
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

  useEffect(() => () => flush(), []);

  return {
    pending,
    refresh,
    refreshThen: (fn) => {
      wait(fn);
      refresh();
    },
    refreshAndWait: () =>
      new Promise((resolve) => {
        wait(resolve);
        refresh();
      }),
    pushAndWait: (href) =>
      new Promise((resolve) => {
        wait(resolve);
        startLeaving(() => router.push(href));
      }),
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
