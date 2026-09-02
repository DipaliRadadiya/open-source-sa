import { useCallback, useEffect, useRef, useState } from "react";
import { cn } from "@/lib/utils";

/**
 * A horizontal scroller that fades only the edge it can still scroll towards.
 *
 * A permanent fade is worse than none: at the end of the strip it still looks
 * like something is cut off, so you keep swiping at nothing. This tracks the
 * scroll position and drops the fade the moment that edge is reached.
 */
export function ScrollFade({ className, children, ...props }) {
  const ref = useRef(null);
  const [edges, setEdges] = useState({ start: false, end: false });

  const measure = useCallback(() => {
    const el = ref.current;
    if (!el) return;
    const max = el.scrollWidth - el.clientWidth;
    setEdges({ start: el.scrollLeft > 1, end: max - el.scrollLeft > 1 });
  }, []);

  useEffect(() => {
    measure();
    const el = ref.current;
    if (!el) return;
    // Content can arrive or the window can change size after mount, and either
    // changes whether there is anything to scroll to.
    const observer = new ResizeObserver(measure);
    observer.observe(el);
    for (const child of el.children) observer.observe(child);
    window.addEventListener("resize", measure);
    return () => {
      observer.disconnect();
      window.removeEventListener("resize", measure);
    };
  }, [measure]);

  const mask =
    edges.start && edges.end
      ? "[mask-image:linear-gradient(to_right,transparent,black_6%,black_94%,transparent)]"
      : edges.end
        ? "[mask-image:linear-gradient(to_right,black_88%,transparent)]"
        : edges.start
          ? "[mask-image:linear-gradient(to_right,transparent,black_12%)]"
          : null;

  return (
    <div
      ref={ref}
      onScroll={measure}
      className={cn("overflow-x-auto", mask, className)}
      {...props}
    >
      {children}
    </div>
  );
}
