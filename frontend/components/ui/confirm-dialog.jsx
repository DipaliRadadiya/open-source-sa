import { useEffect, useRef } from "react";
import { Loader2, TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";

const TONE_CHIP = {
  destructive: "bg-destructive/10 text-destructive",
  warning: "bg-warning/15 text-warning",
  default: "bg-primary/10 text-primary",
};

export function ConfirmDialog({
  open,
  onOpenChange,
  icon: Icon,
  tone = "default",
  title,
  description,
  children,
  cancelLabel = "Cancel",
  confirmLabel = "Confirm",
  confirmVariant,
  confirmDisabled = false,
  pending = false,
  onConfirm,
  // Callers stay open on failure so the user can retry; without this it looks stuck.
  error = null,
  className,
  onCloseAutoFocus,
}) {
  // Cannot be closed (e.g. by Escape) while the request is pending.
  function handleOpenChange(next) {
    if (!next && pending) return;
    onOpenChange?.(next);
  }

  // Ignore confirm clicks in the first 400 ms: the opener's double-click can land on confirm.
  const openedAt = useRef(0);
  useEffect(() => {
    if (open) openedAt.current = Date.now();
  }, [open]);

  return (
    <AlertDialog open={open} onOpenChange={handleOpenChange}>
      {/* A dialog with a body gets max-w-md. The `data-[size=default]:` prefix
          is needed: a plain `sm:max-w-md` loses to the base class on specificity. */}
      <AlertDialogContent
        className={cn(children ? "data-[size=default]:sm:max-w-md" : null, className)}
        onCloseAutoFocus={onCloseAutoFocus}
      >
        {/* min-w-0 on both grid and flex levels, or a long title widens the dialog. */}
        <AlertDialogHeader className="min-w-0">
          <div className="flex min-w-0 items-center gap-3">
            {Icon ? (
              <span
                className={cn(
                  "flex size-10 shrink-0 items-center justify-center rounded-full",
                  TONE_CHIP[tone] ?? TONE_CHIP.default,
                )}
              >
                <Icon className="size-5" />
              </span>
            ) : null}
            <AlertDialogTitle>{title}</AlertDialogTitle>
          </div>
          {description ? (
            <AlertDialogDescription className="pt-1">
              {description}
            </AlertDialogDescription>
          ) : null}
        </AlertDialogHeader>

        {/* min-w-0 lets `truncate` work inside this grid item; space-y-4 matches
            the dialog's own gap-4. */}
        {children ? <div className="min-w-0 space-y-4">{children}</div> : null}

        {/* role="alert" so screen readers announce it. */}
        {error ? (
          <div
            role="alert"
            className="flex min-w-0 items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
          >
            <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
            <span className="min-w-0 break-words">{error}</span>
          </div>
        ) : null}

        <AlertDialogFooter>
          <AlertDialogCancel disabled={pending}>{cancelLabel}</AlertDialogCancel>
          <Button
            variant={confirmVariant ?? (tone === "destructive" ? "destructive" : "default")}
            disabled={pending || confirmDisabled}
            onClick={() => {
              if (Date.now() - openedAt.current < 400) return;
              onConfirm?.();
            }}
          >
            {pending && <Loader2 className="size-4 animate-spin" />}
            {confirmLabel}
          </Button>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
