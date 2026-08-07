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
export function FormModal({
  open,
  onOpenChange,
  icon: Icon,
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
      <DialogHeader className="shrink-0 space-y-0 border-b px-6 py-4 text-left">
        <div className="flex items-center gap-3">
          {Icon ? (
            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
              <Icon className="size-5" />
            </span>
          ) : null}
          <div className="space-y-1">
            <DialogTitle>{title}</DialogTitle>
            <DialogDescription>{description}</DialogDescription>
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
