import { useEffect, useRef } from "react";

export function InstallOutput({ text }) {
  const ref = useRef(null);

  useEffect(() => {
    if (ref.current) ref.current.scrollTop = ref.current.scrollHeight;
  }, [text]);

  return (
    <pre
      ref={ref}
      className="mt-2 max-h-40 overflow-auto rounded-md bg-muted p-2 font-mono text-xs leading-relaxed break-words whitespace-pre-wrap text-muted-foreground"
      aria-live="polite"
    >
      {text}
    </pre>
  );
}
