"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

/**
 * Re-runs the server component on an interval so a page rendered from a
 * snapshot doesn't quietly go stale.
 *
 * Only while the tab is visible: a background tab polling an API is somebody
 * else's server doing work for a screen nobody is looking at. Coming back to
 * the tab refreshes immediately rather than waiting out the interval, because
 * the moment you look is exactly when the numbers matter.
 *
 * `router.refresh()` re-renders the tree in place rather than remounting it, so
 * half-typed form state in client children survives.
 */
export function AutoRefresh({ intervalMs = 10000 }) {
  const router = useRouter();

  useEffect(() => {
    const tick = () => {
      if (!document.hidden) router.refresh();
    };

    const id = setInterval(tick, intervalMs);
    document.addEventListener("visibilitychange", tick);

    return () => {
      clearInterval(id);
      document.removeEventListener("visibilitychange", tick);
    };
  }, [router, intervalMs]);

  return null;
}
