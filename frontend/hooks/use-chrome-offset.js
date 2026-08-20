"use client";

import { useCallback, useState } from "react";

// Only used until the first measurement lands, and only on a first paint where
// the variable has not been published yet. 7rem is what the other readers of
// `--app-chrome` fall back to.
const FALLBACK = 112;

/**
 * The measured height of the sticky header + breadcrumb cluster, in pixels,
 * for use as Radix `collisionPadding`.
 *
 * Radix measures a popover's available space to the *viewport edge*. It has no
 * idea the top of the viewport is occupied by chrome, so a panel that flips
 * upward fills that space quite legitimately and lands over the header and the
 * breadcrumb. Capping the height does not help: the space it is filling really
 * is free as far as the positioning engine is concerned. Telling it to treat
 * the top N pixels as out of bounds is the only thing that does.
 *
 * Read from the `--app-chrome` variable AppChromeHeight publishes rather than
 * hardcoded, because the cluster is not a fixed height — it grows when the
 * reboot-required banner appears, and a constant would be wrong exactly then.
 *
 * Measured on demand rather than held in an effect: the only moment the number
 * matters is when a popover is about to open, and that is also the only moment
 * we can be sure the banner's current state is reflected.
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
