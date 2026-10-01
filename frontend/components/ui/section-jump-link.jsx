"use client";

import { useEffect, useRef } from "react";
import Link from "@/components/ui/app-link";
import { Button } from "@/components/ui/button";

const HIGHLIGHT_MS = 1200;

// Works as a plain link before hydration. `className` renders a bare link, not a Button.
export function SectionJumpLink({ href, children, variant = "outline", size = "sm", className }) {
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

    // Re-add on the next frame so a repeat click restarts the cue.
    highlightFrame.current = requestAnimationFrame(() => {
      target.setAttribute("data-jump-highlight", "true");
      highlightTimer.current = setTimeout(() => {
        target.removeAttribute("data-jump-highlight");
      }, HIGHLIGHT_MS);
    });
  }

  if (className) {
    return (
      <Link href={href} prefetch={false} onClick={jump} className={className}>
        {children}
      </Link>
    );
  }

  return (
    <Button asChild variant={variant} size={size}>
      <Link href={href} prefetch={false} onClick={jump}>
        {children}
      </Link>
    </Button>
  );
}
