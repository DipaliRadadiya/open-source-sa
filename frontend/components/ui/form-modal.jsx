import { cn } from "@/lib/utils";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";

// `asForm` + `onSubmit` wrap body and footer in a <form> so a footer submit works.
const FOCUSABLE =
  "input:not([type=hidden]), textarea, select, button, a[href], [tabindex]:not([tabindex='-1'])";

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
  // Selector for the control to focus when it is not the first one.
  initialFocus,
}) {
  const inner = (
    <>
      {/* pe-10 reserves room for the absolutely positioned close button. */}
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
          {/* min-w-0 + break-words: long user-supplied names must not widen the dialog. */}
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
        // Never focus a label's "?" hint: Radix would open the note.
        onOpenAutoFocus={(event) => {
          const chosen = initialFocus ? event.currentTarget.querySelector(initialFocus) : null;
          if (chosen) {
            event.preventDefault();
            chosen.focus();
            return;
          }
          const field = [...event.currentTarget.querySelectorAll(FOCUSABLE)].find(
            (element) =>
              element.getAttribute("data-slot") !== "info-hint" &&
              element.getAttribute("data-slot") !== "dialog-close" &&
              element.getAttribute("aria-hidden") !== "true" &&
              element.getAttribute("tabindex") !== "-1" &&
              !element.disabled &&
              !element.readOnly &&
              element.getClientRects().length > 0,
          );
          if (field) {
            event.preventDefault();
            field.focus();
          }
        }}
      >
        {asForm ? (
          // noValidate: native validation bubbles are untranslated; the schema handles it.
          <form noValidate onSubmit={onSubmit} className="contents">
            {inner}
          </form>
        ) : (
          inner
        )}
      </DialogContent>
    </Dialog>
  );
}
