// Scrolls to and focuses the first invalid field, which may be off-screen on
// a long form. Pass as react-hook-form's `onInvalid`:
// `handleSubmit(onValid, scrollToFirstError)`.
//
// Scans `document` by default (only one form submits at a time). Runs a frame
// late so react-hook-form has set aria-invalid first.
export function scrollToFirstError(root = typeof document !== "undefined" ? document : null) {
  if (!root) return;
  requestAnimationFrame(() => {
    // First in page order, so a form-level message above the fields wins over
    // a field below it. `data-form-error` marks those messages.
    const target =
      root.querySelector('[data-form-error], [aria-invalid="true"]') ??
      root.querySelector('[data-slot="form-message"]');
    if (!target) return;
    target.scrollIntoView({ behavior: "smooth", block: "center" });
    if (typeof target.focus === "function") target.focus({ preventScroll: true });
  });
}
