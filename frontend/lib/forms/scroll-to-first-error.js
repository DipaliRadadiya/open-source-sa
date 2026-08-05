// On a long form a validation error can land off-screen, so a click on
// Submit looks like it did nothing. Bring the first invalid field into view
// and focus it. Pass as react-hook-form's `onInvalid` — `handleSubmit(onValid,
// scrollToFirstError)` — so it only runs on a failed submit.
//
// Defaults to scanning `document` rather than needing a form ref threaded
// through `FormModal`: only one form is ever being submitted at a time, so
// the first `aria-invalid` field in the document is unambiguous. Runs a tick
// late so react-hook-form has marked the fields aria-invalid first.
export function scrollToFirstError(root = typeof document !== "undefined" ? document : null) {
  if (!root) return;
  requestAnimationFrame(() => {
    const target =
      root.querySelector('[aria-invalid="true"]') ??
      root.querySelector('[data-slot="form-message"]');
    if (!target) return;
    target.scrollIntoView({ behavior: "smooth", block: "center" });
    if (typeof target.focus === "function") target.focus({ preventScroll: true });
  });
}
