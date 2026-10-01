import { useEffect, useRef } from "react";

// Scrolled to the last line, where installers state why they stopped.
export function InstallOutput({ text }) {
  const ref = useRef(null);

  useEffect(() => {
    if (ref.current) ref.current.scrollTop = ref.current.scrollHeight;
  }, [text]);

  return (
    <pre
      ref={ref}
      // Wrapped: the reason is usually one long line.
      className="mt-2 max-h-40 overflow-auto rounded-md bg-muted p-2 font-mono text-xs leading-relaxed break-words whitespace-pre-wrap text-muted-foreground"
      // Polite: it updates every poll, and assertive would keep interrupting a screen reader.
      aria-live="polite"
    >
      {text}
    </pre>
  );
}
