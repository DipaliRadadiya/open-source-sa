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
 *   - above 100 clamps to 100, rather than being rejected after the fact
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

  return String(Math.min(value, 100));
}
