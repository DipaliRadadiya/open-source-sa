// Pass as react-hook-form's `onInvalid`. Runs a frame late so aria-invalid is set first.
export function scrollToFirstError(root = typeof document !== "undefined" ? document : null) {
  if (!root) return;
  requestAnimationFrame(() => {
    // First in page order, so a form-level message (`data-form-error`) above the fields wins.
    const target =
      root.querySelector('[data-form-error], [aria-invalid="true"]') ??
      root.querySelector('[data-slot="form-message"]');
    if (!target) return;
    target.scrollIntoView({ behavior: "smooth", block: "center" });
    if (typeof target.focus === "function") target.focus({ preventScroll: true });
  });
}
