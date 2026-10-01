import { useEffect, useState } from "react";

import { cn } from "@/lib/utils";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

// Tooltip only when actually clipped; passing `tooltip` always shows it.
export function TruncatedText({ children, tooltip, className, as: Tag = "span" }) {
  // A callback ref, NOT `useRef`: the node remounts inside a TooltipTrigger when
  // clipped, and a detached node measures 0×0 ("fits").
  const [node, setNode] = useState(null);
  const [clipped, setClipped] = useState(false);

  useEffect(() => {
    if (!node) return undefined;

    // +1 tolerates sub-pixel layout on text that is not clipped.
    const measure = () => setClipped(node.scrollWidth > node.clientWidth + 1);
    measure();

    // Container resizes (sidebar, zoom) do not fire a window resize.
    const observer = new ResizeObserver(measure);
    observer.observe(node);
    return () => observer.disconnect();
  }, [node, children]);

  const line = (
    <Tag
      ref={setNode}
      // Exposes the measurement for tests.
      data-clipped={clipped ? "true" : "false"}
      className={cn("block truncate", className)}
    >
      {children}
    </Tag>
  );

  if (!clipped && !tooltip) return line;

  return (
    <Tooltip>
      <TooltipTrigger asChild>{line}</TooltipTrigger>
      {/* Wraps long text instead of one window-wide line. */}
      <TooltipContent className="max-w-xs text-pretty">{tooltip ?? children}</TooltipContent>
    </Tooltip>
  );
}
