import { useCallback, useState } from "react";

const FALLBACK = 112;

// Keeps Radix popovers from flipping up under the sticky header.
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
