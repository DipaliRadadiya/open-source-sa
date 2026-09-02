import { TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * The panel an error boundary renders in place of the thing that threw.
 *
 * All four boundaries — root, server panel, admin and sign-in — drew this by
 * hand, and `diff` between two of them was empty once the comments and the
 * function name were stripped. Two of those copies were made this week, from
 * the copy before them, which is how four of a thing happen.
 *
 * Deliberately fixed rather than configurable: the icon, the destructive tone
 * and `role="alert"` are the same in every case and should not be a decision a
 * call site can get wrong. `role` in particular — a boundary that forgot it
 * would fail silently for anyone using a screen reader, and nothing would ever
 * report it.
 *
 * Deliberately NOT used by `LoadFailed` or `RateLimited`. Those look similar
 * and are not: one reports a section failing inside a working page and shows an
 * HTTP status, the other is amber on purpose because nothing is broken and
 * waiting fixes it. Folding them in would mean props for tone, icon, spacing
 * and detail, and the reason each is what it is would stop being visible.
 *
 * No hooks and no data, on purpose. The root boundary renders this, and if it
 * threw there would be nothing left to catch it.
 *
 * @param title       the heading — what happened
 * @param description one sentence on what to do about it
 * @param detail      optional, small and last: the digest support can look up
 * @param action      the retry control, passed as a node so each boundary keeps
 *                    its own `reset` and this stays unaware of boundaries
 * @param className   spacing only; the four differ in vertical padding
 * @param centered    wraps in a full-height centring flex, for a boundary that
 *                    replaces the whole viewport rather than a slot inside one
 */
export function FailurePanel({
  title,
  description,
  detail = null,
  action = null,
  className,
  centered = false,
}) {
  const panel = (
    <div
      role="alert"
      className={cn(
        "flex flex-col items-center gap-4 rounded-xl border border-destructive/30 bg-destructive/5 px-6 py-12 text-center",
        className,
      )}
    >
      <span className="flex size-12 items-center justify-center rounded-full bg-destructive/10 text-destructive">
        <TriangleAlert className="size-5" />
      </span>
      <div className="space-y-1">
        <p className="font-medium">{title}</p>
        <p className="max-w-md text-sm text-muted-foreground">{description}</p>
        {/* The digest, not `error.message`: the raw message can leak server
            internals and means nothing to the reader, while the digest is what
            correlates with the server log. */}
        {detail ? (
          <p className="pt-1 font-mono text-xs text-muted-foreground">{detail}</p>
        ) : null}
      </div>
      {action}
    </div>
  );

  if (!centered) return panel;

  return <div className="flex min-h-svh items-center justify-center p-6">{panel}</div>;
}
