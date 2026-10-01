// Digits only, no clamping (`thresholdProblem` reports range); `0` empties the
// field, since an empty box already means "always".
export function clampPercent(input) {
  const digits = String(input ?? "").replace(/[^0-9]/g, "");
  if (digits === "") return "";

  const value = Number(digits.slice(0, 3));
  if (value <= 0) return "";

  return String(value);
}

/** Usage is never above 100%, and "above 100" can never fire: 1–99. */
export function thresholdProblem(value) {
  if (value === "" || value === null || value === undefined) return null;
  const number = Number(value);
  return Number.isInteger(number) && number >= 1 && number <= 99 ? null : "range";
}
