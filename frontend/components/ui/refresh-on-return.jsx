"use client";

import { useEffect, useRef } from "react";
import { useRouter } from "next/navigation";

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
