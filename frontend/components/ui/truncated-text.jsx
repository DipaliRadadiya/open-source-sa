import { useEffect, useState } from "react";

import { cn } from "@/lib/utils";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

/**
 * One line of text that offers the rest of itself when it does not fit.
 *
 * `truncate` is a lie of omission: "Marketing automation and ca…" reads as a
 * complete thought right up until it matters. The native `title` attribute is
 * the usual patch and a poor one — it waits a second, styles itself like
 * Windows 98, and is invisible to a finger.
 *
 * So the tooltip is the panel's own, and it appears only when the text is
 * ACTUALLY clipped: a tooltip that repeats what is already on screen trains
 * people to ignore tooltips. That is measured, not guessed — the same string
 * fits at one width and not another, and no locale can be assumed to be the
 * tight one.
 *
 * `tooltip` overrides what the bubble says, for the case where the visible
 * line is a SUMMARY rather than a truncation — a blocked card shows "Needs
 * MySQL or MariaDB" and can explain itself with the server's own sentence.
 * Passing it also means the bubble is always offered, since there is more to
 * say whether or not the line happens to fit.
 */
export function TruncatedText({ children, tooltip, className, as: Tag = "span" }) {
  /*
   * A callback ref into state, NOT `useRef` — and that is the whole bug this
   * component shipped with on its first outing.
   *
   * Finding the text clipped swaps the plain span for one inside a
   * TooltipTrigger. That is a different position in the tree, so React
   * unmounts the element that was measured and mounts a new one. With a
   * `useRef` the effect did not re-run, so the ResizeObserver stayed pointed
   * at the DETACHED node — which reports 0×0 and therefore measures as "fits".
   * Straight back to the plain span, forever, at zero tooltips: every
   * measurement correct, the state flipping back each time.
   *
   * A callback ref re-runs the effect on whichever node is actually on screen,
   * so the observer follows the element through the swap and settles.
   */
  const [node, setNode] = useState(null);
  const [clipped, setClipped] = useState(false);

  useEffect(() => {
    if (!node) return undefined;

    // +1: a sub-pixel layout can leave scrollWidth a hair over clientWidth on
    // text that is plainly not clipped, which would put a bubble on every card.
    const measure = () => setClipped(node.scrollWidth > node.clientWidth + 1);
    measure();

    // The column this sits in resizes with the sidebar, the summary panel and
    // the reader's zoom — none of which fire a window resize.
    const observer = new ResizeObserver(measure);
    observer.observe(node);
    return () => observer.disconnect();
  }, [node, children]);

  const line = (
    <Tag
      ref={setNode}
      // Not decoration: the measurement IS the behaviour here, and without it
      // on the element a test can only observe the tooltip it was supposed to
      // cause — which is how the swap above went unseen.
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
      {/* Wrapped, not one endless line: the server's reasons run to a sentence
          and a half, and a tooltip as wide as the window is unreadable. */}
      <TooltipContent className="max-w-xs text-pretty">{tooltip ?? children}</TooltipContent>
    </Tooltip>
  );
}
