"use client";

import { Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";

// Shared confirmation dialog. Bakes in the panel's icon-circle header so it can
// never drift. `tone` drives the icon-chip tint (and the default confirm
// button variant). `children` is an optional extra body slot (e.g. a
// type-to-confirm input).
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
  // Widening is opt-in: a yes/no confirmation should stay narrow, but one that
  // asks you to review a list needs the room.
  className,
}) {
  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent className={className}>
        <AlertDialogHeader>
          <div className="flex items-center gap-3">
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

        {/* The dialog body is a grid, and a grid item's `min-width` defaults to
            `auto` — so a child holding long unbroken strings (database names,
            paths) grows the whole dialog past its own max-width instead of
            truncating inside it. `min-w-0` is what makes `truncate` work at
            all in here. */}
        {children ? <div className="min-w-0">{children}</div> : null}

        <AlertDialogFooter>
          <AlertDialogCancel disabled={pending}>{cancelLabel}</AlertDialogCancel>
          <AlertDialogAction
            variant={confirmVariant ?? (tone === "destructive" ? "destructive" : "default")}
            disabled={pending || confirmDisabled}
            onClick={(e) => {
              e.preventDefault();
              onConfirm?.();
            }}
          >
            {pending && <Loader2 className="size-4 animate-spin" />}
            {confirmLabel}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
