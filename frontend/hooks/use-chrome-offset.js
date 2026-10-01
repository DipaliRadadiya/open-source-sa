import { useCallback, useState } from "react";

// Used only until the first measurement; matches other `--app-chrome` fallbacks (7rem).
const FALLBACK = 112;

/**
 * The measured height of the sticky header + breadcrumb cluster, in pixels,
 * for use as Radix `collisionPadding`. Radix measures space to the viewport
 * edge and would otherwise flip a popover up over the header.
 *
 * Read from `--app-chrome` (published by AppChromeHeight) because the cluster
 * grows with the reboot banner. Measured on demand, when a popover opens.
 */
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
