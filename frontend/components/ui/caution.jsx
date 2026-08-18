import { TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * A consequence the reader has to know before they act, said next to the thing
 * that causes it.
 *
 * Amber rather than red: nothing is wrong and they may well mean it — they just
 * have to know beforehand rather than after the next run. Kept small and inline
 * so it reads as a note attached to a control, not as a page-level alert.
 */
export function Caution({ children, className }) {
  return (
    <p
      className={cn(
        "flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-2.5 text-xs",
        className,
      )}
    >
      <TriangleAlert className="mt-px size-3.5 shrink-0 text-warning" />
      <span className="min-w-0">{children}</span>
    </p>
  );
}
