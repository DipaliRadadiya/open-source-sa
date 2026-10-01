"use client";

import { useEffect, useRef } from "react";

/**
 * The installer's own output (apt for PHP, fnm for Node), held at its last
 * line. Installers say why they stopped at the END — "E: Held packages were
 * changed…" — and the box opened at the top, on "Reading package lists…", so a
 * failed install showed everything except the reason.
 */
export function InstallOutput({ text }) {
  const ref = useRef(null);

  useEffect(() => {
    if (ref.current) ref.current.scrollTop = ref.current.scrollHeight;
  }, [text]);

  return (
    <pre
      ref={ref}
      // Wrapped: the reason is usually one long line, and on a phone it was
      // cut at the edge of a box you had to scroll sideways to read.
      className="mt-2 max-h-40 overflow-auto rounded-md bg-muted p-2 font-mono text-xs leading-relaxed break-words whitespace-pre-wrap text-muted-foreground"
      // Announced politely: this updates every poll while an install
      // runs, and an assertive region would interrupt a screen reader
      // several times a minute for output nobody asked to hear.
      aria-live="polite"
    >
      {text}
    </pre>
  );
}
