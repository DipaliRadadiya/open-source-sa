export function scrollToFirstError(root = typeof document !== "undefined" ? document : null) {
  if (!root) return;
  requestAnimationFrame(() => {
    const target =
      root.querySelector('[data-form-error], [aria-invalid="true"]') ??
      root.querySelector('[data-slot="form-message"]');
    if (!target) return;
    target.scrollIntoView({ behavior: "smooth", block: "center" });
    if (typeof target.focus === "function") target.focus({ preventScroll: true });
  });
}
