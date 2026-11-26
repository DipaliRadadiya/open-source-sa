import { useEffect, useState } from "react";

import { cn } from "@/lib/utils";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

export function TruncatedText({ children, tooltip, className, as: Tag = "span" }) {
  const [node, setNode] = useState(null);
  const [clipped, setClipped] = useState(false);

  useEffect(() => {
    if (!node) return undefined;

    const measure = () => setClipped(node.scrollWidth > node.clientWidth + 1);
    measure();

    // Container resizes (sidebar, zoom) don't fire window resize.
    const observer = new ResizeObserver(measure);
    observer.observe(node);
    return () => observer.disconnect();
  }, [node, children]);

  const line = (
    <Tag
      ref={setNode}
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
      <TooltipContent className="max-w-xs text-pretty">{tooltip ?? children}</TooltipContent>
    </Tooltip>
  );
}
