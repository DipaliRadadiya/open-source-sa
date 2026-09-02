import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Copy, Check, RotateCw } from "lucide-react";

/**
 * Toasts for a service action, shared by the row buttons and the boot switch.
 *
 * Everything interactive lives inside the toast body rather than Sonner's
 * `action` slot: that slot sits beside the text and won't shrink, so a
 * two-line message pushed the button clean outside the toast.
 */

const COPIED_RESET_MS = 2000;

function ToastBody({ message, reference, copyLabel, copiedLabel, actionLabel, onAction }) {
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    if (!copied) return undefined;
    const id = setTimeout(() => setCopied(false), COPIED_RESET_MS);
    return () => clearTimeout(id);
  }, [copied]);

  async function copy() {
    try {
      await navigator.clipboard.writeText(reference);
      setCopied(true);
    } catch {
      /* Clipboard refused — the code is on screen to select by hand. */
    }
  }

  return (
    <span className="mt-1 flex w-full min-w-0 flex-col gap-2">
      {message ? <span>{message}</span> : null}

      {reference ? (
        <span className="flex w-full min-w-0 items-center gap-1.5">
          {/* min-w-0 + flex-1: the reference is the only part that may be cut
              short. Without it the confirmation label gets squeezed and breaks
              mid-word ("Copie / d"). */}
          <span className="min-w-0 flex-1 truncate font-mono text-xs">{reference}</span>
          <button
            type="button"
            aria-label={copied ? copiedLabel : copyLabel}
            onClick={copy}
            className="shrink-0 rounded p-0.5 opacity-70 transition-opacity hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
          >
            {/* The icon swap IS the confirmation. A word alongside it kept
                breaking the row — the toast is too narrow to hold a UUID and a
                label — and the tick carries the same meaning in no space at
                all. The state is announced through aria-label instead. */}
            {copied ? (
              <Check className="size-3.5 text-success" />
            ) : (
              <Copy className="size-3.5" />
            )}
          </button>
        </span>
      ) : null}

      {onAction ? (
        <button
          type="button"
          onClick={onAction}
          className="inline-flex w-fit items-center gap-1.5 rounded-md border bg-background px-2 py-1 text-xs font-medium text-foreground transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
        >
          <RotateCw className="size-3" />
          {actionLabel}
        </button>
      ) : null}
    </span>
  );
}

export function showActionError({
  title,
  message,
  reference,
  copyLabel,
  copiedLabel,
  retryLabel,
  onRetry,
}) {
  // Both the sentence and the reference, now. This used to drop the server's
  // sentence whenever a reference existed, which was reasonable while every
  // failure produced the same generic "the operation failed": an id was strictly
  // more useful than a sentence saying nothing.
  //
  // The backend now names the step that actually failed — "the log file could
  // not be handed to that account" — so the sentence carries the cause and the
  // reference only identifies the incident. Dropping the cause to show an id
  // would be exactly backwards.
  toast.error(title, {
    description: (
      <ToastBody
        message={message}
        reference={reference}
        copyLabel={copyLabel}
        copiedLabel={copiedLabel}
        actionLabel={retryLabel}
        onAction={onRetry}
      />
    ),
    // A reference code has to outlive the glance that spots it — you can't quote
    // a UUID that vanished while you were reading it.
    duration: reference ? Infinity : 10000,
    closeButton: true,
  });
}

export function showActionSuccess({ title, undoLabel, onUndo }) {
  toast.success(title, {
    description: onUndo ? (
      <ToastBody actionLabel={undoLabel} onAction={onUndo} />
    ) : undefined,
  });
}
