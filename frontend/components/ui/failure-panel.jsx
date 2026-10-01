import { TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * What every error boundary (root, server panel, admin, sign-in) renders.
 * Icon, tone and `role="alert"` are fixed on purpose. Not for `LoadFailed` or
 * `RateLimited`, which differ deliberately.
 *
 * No hooks and no data: the root boundary renders this, and nothing would
 * catch a throw here.
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
        {/* The digest, not `error.message`, which can leak server internals. */}
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
