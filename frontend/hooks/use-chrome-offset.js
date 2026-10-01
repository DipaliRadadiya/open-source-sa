import { useCallback, useState } from "react";

// Used only until the first measurement; matches other `--app-chrome` fallbacks (7rem).
const FALLBACK = 112;

// Radix `collisionPadding` for the sticky header cluster, or popovers flip up
// over it. Read from `--app-chrome` on open: the cluster grows with the reboot banner.
export function useChromeOffset() {
  const [offset, setOffset] = useState(FALLBACK);

  const measure = useCallback(() => {
    if (typeof window === "undefined") return;
    const raw = getComputedStyle(document.documentElement).getPropertyValue("--app-chrome");
    const value = Number.parseFloat(raw);
    setOffset(Number.isFinite(value) && value > 0 ? value : FALLBACK);
  }, []);

  return [offset, measure];
}
