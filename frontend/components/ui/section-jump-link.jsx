"use client";

import { useEffect, useRef } from "react";
import Link from "next/link";
import { Button } from "@/components/ui/button";

const HIGHLIGHT_MS = 1200;

/**
 * A same-page link that makes its destination briefly identify itself.
 *
 * The href stays a real fragment link, so the browser still reaches the
 * section before hydration or when JavaScript is unavailable. Once hydrated,
 * the extra cue confirms the jump even when the target was already nearby.
 */
export function SectionJumpLink({ href, children, variant = "outline", size = "sm" }) {
  const highlightTimer = useRef(null);
  const highlightFrame = useRef(null);

  useEffect(
    () => () => {
      clearTimeout(highlightTimer.current);
      cancelAnimationFrame(highlightFrame.current);
    },
    [],
  );

  function jump(event) {
    const id = href.startsWith("#") ? decodeURIComponent(href.slice(1)) : "";
    const target = id ? document.getElementById(id) : null;
    if (!target) return;

    event.preventDefault();

    if (window.location.hash !== href) {
      window.history.pushState(null, "", href);
    }

    const reduceMotion = window.matchMedia(
      "(prefers-reduced-motion: reduce)",
    ).matches;
    target.scrollIntoView({
      behavior: reduceMotion ? "auto" : "smooth",
      block: "start",
    });
    target.focus({ preventScroll: true });

    clearTimeout(highlightTimer.current);
    cancelAnimationFrame(highlightFrame.current);
    target.removeAttribute("data-jump-highlight");

    // Re-add on the next frame so clicking the link again while the cue is
    // still visible restarts it instead of looking like the click did nothing.
    highlightFrame.current = requestAnimationFrame(() => {
      target.setAttribute("data-jump-highlight", "true");
      highlightTimer.current = setTimeout(() => {
        target.removeAttribute("data-jump-highlight");
      }, HIGHLIGHT_MS);
    });
  }

  return (
    <Button asChild variant={variant} size={size}>
      <Link href={href} prefetch={false} onClick={jump}>
        {children}
      </Link>
    </Button>
  );
}
