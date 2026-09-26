"use client";

import { useEffect, useRef } from "react";
import { useRouter } from "next/navigation";

/**
 * Re-read the page when the reader comes back to this tab after a while.
 *
 * For lists that change from elsewhere — an application created in another
 * tab never appeared in an Applications list left open, however long it
 * waited. Not a poll: nothing is fetched while the tab is in use or hidden.
 */
export function RefreshOnReturn({ afterMs = 10000 }) {
  const router = useRouter();
  const hiddenAt = useRef(null);

  useEffect(() => {
    function onChange() {
      if (document.hidden) {
        hiddenAt.current = Date.now();
        return;
      }
      if (hiddenAt.current && Date.now() - hiddenAt.current >= afterMs) router.refresh();
      hiddenAt.current = null;
    }
    document.addEventListener("visibilitychange", onChange);
    return () => document.removeEventListener("visibilitychange", onChange);
  }, [router, afterMs]);

  return null;
}
