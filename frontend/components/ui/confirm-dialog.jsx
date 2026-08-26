"use client";

import { Loader2 } from "lucide-react";
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
      {/* A confirmation carrying a body — a checkbox to weigh up, a list to
          review, a domain to type — gets more width, because the alternative is
          the same words in a taller, narrower column. The delete-site dialog
          was 384px wide and 497px tall.

          md, not lg: at 512px the height was identical to 448px — the body is
          fixed blocks, not wrapping prose, so past this point the extra width
          buys nothing and only makes the dialog wide.

          Keyed on `children` rather than asked of every caller: having a body
          IS the signal, and a prop would be forgotten. Still `size=default`
          — the header and footer alignment rules are written against that
          size, so a new one would centre the title. Hence the matching
          `data-[size=default]:` prefix: a plain `sm:max-w-md` loses to the
          base class on specificity and silently does nothing. */}
      <AlertDialogContent
        className={cn(children ? "data-[size=default]:sm:max-w-md" : null, className)}
      >
        {/* min-w-0 twice, because the title is two levels deep: the header is a
            grid item of the dialog and the title is a flex item of this row.
            Either one defaulting to min-width:auto is enough to widen the
            dialog's content past the box and push the confirm button off a
            narrow screen. */}
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

        {/* The dialog body is a grid, and a grid item's `min-width` defaults to
            `auto` — so a child holding long unbroken strings (database names,
            paths) grows the whole dialog past its own max-width instead of
            truncating inside it. `min-w-0` is what makes `truncate` work at
            all in here. */}
        {/* `space-y` because the body regularly holds more than one block — a
            list of what is about to be deleted AND the checkbox that changes
            what deleting means. Without it those two sit flush against each
            other and read as one control. A dialog passing a single child is
            unaffected: space-y only ever applies between siblings.

            `4`, matching the `gap-4` the dialog puts between header, body and
            footer. At `3` the body packed its own blocks tighter than the
            dialog packs everything around them, so two bordered cards — the
            file list and the permanent-delete box — sat 12px apart inside 16px
            surroundings and read as cramped. */}
        {children ? <div className="min-w-0 space-y-4">{children}</div> : null}

        <AlertDialogFooter>
          <AlertDialogCancel disabled={pending}>{cancelLabel}</AlertDialogCancel>
          <Button
            variant={confirmVariant ?? (tone === "destructive" ? "destructive" : "default")}
            disabled={pending || confirmDisabled}
            onClick={() => onConfirm?.()}
          >
            {pending && <Loader2 className="size-4 animate-spin" />}
            {confirmLabel}
          </Button>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
