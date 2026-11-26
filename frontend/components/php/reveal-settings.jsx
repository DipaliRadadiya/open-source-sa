"use client";

import { useEffect } from "react";

const KEY = "php-reveal-settings";

export function requestReveal(version) {
  try {
    sessionStorage.setItem(KEY, version);
  } catch {
    // storage blocked; ignore
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
    if (target && target.getBoundingClientRect().top > window.innerHeight * 0.7) {
      target.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }, [version, targetId]);

  return null;
}
