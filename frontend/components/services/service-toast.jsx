import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Copy, Check, RotateCw } from "lucide-react";

// Interactive parts live in the body, not Sonner's `action` slot: it won't shrink on two-line messages.

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
          {/* Only the reference may be truncated. */}
          <span className="min-w-0 flex-1 truncate font-mono text-xs">{reference}</span>
          <button
            type="button"
            aria-label={copied ? copiedLabel : copyLabel}
            onClick={copy}
            className="shrink-0 rounded p-0.5 opacity-70 transition-opacity hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
          >
            {/* The icon swap is the confirmation; the state is announced through aria-label. */}
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
  // The backend names the failed step; the reference identifies the incident.
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
  // A toast with a reference stays until dismissed, so the code can be quoted.
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
