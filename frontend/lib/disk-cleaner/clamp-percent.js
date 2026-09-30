/**
 * What the disk-usage threshold box should hold after a keystroke.
 *
 * The field used to accept any three digits, so `150` could be typed and saved
 * — and the API rejects anything outside 1–100 with a 422 the reader cannot
 * act on. A number that will be refused should not be typeable in the first
 * place.
 *
 * Three rules, in the order they matter:
 *
 *   - digits only, so a stray letter or minus never reaches the value
 *   - no clamping: 150 used to become 100 without a word, and "above 100%"
 *     never happens, so the schedule showed On and never ran. The card says
 *     what is allowed instead (`thresholdProblem`).
 *   - `0` empties the field, because "run when usage is above 0%" is what an
 *     empty box already means. Mapping it to nothing keeps the two ways of
 *     saying "always" as one state instead of one valid and one refused.
 *
 * Leading zeros are dropped for the same reason: `080` is `80`, and sending
 * the string through unchanged would let it grow past three characters.
 */
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
