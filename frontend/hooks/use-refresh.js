"use client";

import { useTransition } from "react";
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
 */
export function useRefresh() {
  const nav = useNavTransition();
  const router = useRouter();
  const [localPending, startLocal] = useTransition();

  return {
    pending: nav ? nav.isPending : localPending,
    refresh: nav ? nav.refresh : () => startLocal(() => router.refresh()),
  };
}
