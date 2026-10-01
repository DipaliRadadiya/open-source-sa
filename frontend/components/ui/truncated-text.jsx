import { useEffect, useState } from "react";

import { cn } from "@/lib/utils";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

/**
 * One truncated line with a tooltip showing the full text, only when it is
 * actually clipped (measured, not guessed).
 *
 * `tooltip` overrides the bubble text when the line is a summary rather than a
 * truncation; passing it always shows the bubble.
 */
export function TruncatedText({ children, tooltip, className, as: Tag = "span" }) {
  /*
   * A callback ref into state, NOT `useRef`: when clipped, the element is
   * remounted inside a TooltipTrigger, and the observer must follow the new
   * node (a detached one measures 0×0, i.e. "fits").
   */
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
