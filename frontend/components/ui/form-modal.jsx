"use client";

import { cn } from "@/lib/utils";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";

// Shared modal shell for forms and rich content: primary icon-chip header,
// scrollable body, pinned footer bar — the panel's premium dialog chrome, in
// one place so it can't drift.
//
// Pass `asForm` + `onSubmit` to wrap the body+footer in a <form> (so a submit
// button in the footer works). `children` is the body; `footer` is the footer
// slot (buttons).
// Primary everywhere by default. `success` exists for the dialogs that report
// a finished action rather than ask for one — a blue tick over "…is ready"
// says "information", and the moment deserves the colour the rest of the panel
// already uses for a good outcome.
const ICON_TONES = {
  primary: "bg-primary/10 text-primary",
  success: "bg-success/10 text-success",
};

export function FormModal({
  open,
  onOpenChange,
  icon: Icon,
  iconTone = "primary",
  title,
  description,
  children,
  footer,
  asForm = false,
  onSubmit,
  className,
}) {
  const inner = (
    <>
      {/* pe-10 reserves the close button's corner. It is absolutely positioned,
          so it takes no space in the flow — a long title happily runs underneath
          it and the last word becomes unreadable. */}
      <DialogHeader className="shrink-0 space-y-0 border-b py-4 pe-10 ps-6 text-left">
        <div className="flex items-center gap-3">
          {Icon ? (
            <span
              className={cn(
                "flex size-9 shrink-0 items-center justify-center rounded-lg",
                ICON_TONES[iconTone] ?? ICON_TONES.primary,
              )}
            >
              <Icon className="size-5" />
            </span>
          ) : null}
          {/* min-w-0 + break-words: titles carry user-supplied names ("Add a
              user to wp_1395988213_nip_io_zi6nod"), and an unbreakable 26-char
              token in a flex item with min-width:auto made the whole dialog
              wider than the phone it was on — every control cut off at the
              right edge. */}
          <div className="min-w-0 space-y-1">
            <DialogTitle className="break-words">{title}</DialogTitle>
            <DialogDescription className="break-words">{description}</DialogDescription>
          </div>
        </div>
      </DialogHeader>

      <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-4">
        {children}
      </div>

      {footer ? (
        <div className="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t bg-muted/50 px-6 py-4">
          {footer}
        </div>
      ) : null}
    </>
  );

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className={cn(
          "flex max-h-[90vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-lg",
          className,
        )}
      >
        {asForm ? (
          <form onSubmit={onSubmit} className="contents">
            {inner}
          </form>
        ) : (
          inner
        )}
      </DialogContent>
    </Dialog>
  );
}
