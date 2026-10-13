"use client";

import { useEffect } from "react";

const KEY = "php-reveal-settings";

// Called by a row click: on a phone the settings sit below every stacked row, so picking a
// version would otherwise change nothing the reader can see.
export function requestReveal(version) {
  try {
    sessionStorage.setItem(KEY, version);
  } catch {
    // Storage blocked: the page still works, it just does not scroll.
  }
}

export function RevealSettings({ version, targetId }) {
  useEffect(() => {
    let wanted = null;
    try {
      wanted = sessionStorage.getItem(KEY);
      sessionStorage.removeItem(KEY);
    } catch {
      return;
    }
    if (wanted !== version) return;
    const target = document.getElementById(targetId);
    // Only when it is off screen: on a wide layout it is already in view, and a jump would lose the table.
    if (target && target.getBoundingClientRect().top > window.innerHeight * 0.7) {
      target.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }, [version, targetId]);

  return null;
}
