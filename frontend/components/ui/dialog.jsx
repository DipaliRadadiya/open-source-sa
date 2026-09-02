import * as React from "react"
import { Dialog as DialogPrimitive } from "radix-ui"

import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { XIcon } from "lucide-react"

function Dialog({
  ...props
}) {
  return <DialogPrimitive.Root data-slot="dialog" {...props} />;
}

function DialogTrigger({
  ...props
}) {
  return <DialogPrimitive.Trigger data-slot="dialog-trigger" {...props} />;
}

function DialogPortal({
  ...props
}) {
  return <DialogPrimitive.Portal data-slot="dialog-portal" {...props} />;
}

function DialogClose({
  ...props
}) {
  return <DialogPrimitive.Close data-slot="dialog-close" {...props} />;
}

function DialogOverlay({
  className,
  ...props
}) {
  return (
    <DialogPrimitive.Overlay
      data-slot="dialog-overlay"
      className={cn(
        "fixed inset-0 isolate z-50 bg-black/10 duration-100 supports-backdrop-filter:backdrop-blur-xs data-open:animate-in data-open:fade-in-0 data-closed:animate-out data-closed:fade-out-0",
        className
      )}
      {...props} />
  );
}

function DialogContent({
  className,
  children,
  showCloseButton = true,
  ...props
}) {
  return (
    <DialogPortal>
      <DialogOverlay />
      <DialogPrimitive.Content
        data-slot="dialog-content"
        className={cn(
          "fixed top-1/2 left-1/2 z-50 grid w-full max-w-[calc(100%-2rem)] -translate-x-1/2 -translate-y-1/2 gap-4 rounded-xl bg-popover p-4 text-sm text-popover-foreground ring-1 ring-foreground/10 duration-100 outline-none sm:max-w-sm data-open:animate-in data-open:fade-in-0 data-open:zoom-in-95 data-closed:animate-out data-closed:fade-out-0 data-closed:zoom-out-95",
          className
        )}
        {...props}>
        {children}
        {showCloseButton && (
          <DialogPrimitive.Close data-slot="dialog-close" asChild>
            <Button variant="ghost" className="absolute top-2 right-2" size="icon-sm">
              <XIcon />
              <span className="sr-only">Close</span>
            </Button>
          </DialogPrimitive.Close>
        )}
      </DialogPrimitive.Content>
    </DialogPortal>
  );
}

function DialogHeader({
  className,
  ...props
}) {
  return (
    <div
      data-slot="dialog-header"
      className={cn("flex flex-col gap-2", className)}
      {...props} />
  );
}

function DialogFooter({
  className,
  showCloseButton = false,
  children,
  ...props
}) {
  return (
    <div
      data-slot="dialog-footer"
      /* `min-w-0` and `flex-wrap` together stop a long pair of buttons from
         widening the whole dialog.

         DialogContent is a grid, and a grid item defaults to `min-width: auto`
         — it refuses to shrink below its min-content width. A footer with two
         verbose labels ("Turn it on anyway" / "Add this address and turn it
         on") measures wider than the `sm:max-w-sm` track, so the track grew to
         fit it, every stretched sibling grew with it, and the input and footer
         painted outside the dialog's own background while the description —
         being text, which can wrap — stayed inside.

         `min-w-0` lets the item shrink to the track; `flex-wrap` gives the
         buttons somewhere to go when it does. Neither has any effect on a
         footer that already fits.

         A right-aligned wrapping row at EVERY width, matching AlertDialogFooter
         and FormModal. It used to be flex-col-reverse below sm, and stacking is
         what exposed a second bug: a button wrapped in ReasonTooltip is a span
         in the flex flow, so the span stretched to full width while the button
         inside stayed content-sized — "Save and reload" came out 139px sitting
         above a 326px Cancel. Sizing every button to its own label removes the
         mismatch rather than chasing it. */
      className={cn(
        "-mx-4 -mb-4 flex min-w-0 flex-wrap items-center justify-end gap-2 rounded-b-xl border-t bg-muted/50 p-4",
        className
      )}
      {...props}>
      {children}
      {showCloseButton && (
        <DialogPrimitive.Close asChild>
          <Button variant="outline">Close</Button>
        </DialogPrimitive.Close>
      )}
    </div>
  );
}

function DialogTitle({
  className,
  ...props
}) {
  return (
    <DialogPrimitive.Title
      data-slot="dialog-title"
      // min-w-0 + wrap-anywhere, because dialog titles carry user-supplied names
      // ("Delete nextcloud_1395988213_nip_wbixj3"). A flex/grid item defaults to
      // min-width:auto, so one unbreakable token sets the whole dialog's
      // min-content width and pushes its buttons off the side of a phone.
      // wrap-anywhere rather than break-words: only "anywhere" is counted when
      // the browser computes min-content, and min-content is the number doing
      // the damage. break-words breaks the line but leaves the box just as wide.
      className={cn("min-w-0 font-heading text-base leading-none font-medium wrap-anywhere", className)}
      {...props} />
  );
}

function DialogDescription({
  className,
  ...props
}) {
  return (
    <DialogPrimitive.Description
      data-slot="dialog-description"
      className={cn(
        "min-w-0 text-sm wrap-anywhere text-muted-foreground *:[a]:underline *:[a]:underline-offset-3 *:[a]:hover:text-foreground",
        className
      )}
      {...props} />
  );
}

export {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogOverlay,
  DialogPortal,
  DialogTitle,
  DialogTrigger,
}
