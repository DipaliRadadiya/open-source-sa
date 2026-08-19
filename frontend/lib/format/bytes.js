// Shared byte-rate formatting for the metrics charts. Units are technical
// tokens and stay untranslated; only the number is localized, so pass a
// next-intl formatter (useFormatter/getFormatter) as `format`.

const RATE_UNITS = ["B/s", "KB/s", "MB/s", "GB/s", "TB/s"];

/** Scales a bytes-per-second value into the largest unit that keeps it >= 1. */
export function scaleRate(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return null;
  let scaled = Math.abs(n);
  let index = 0;
  while (scaled >= 1024 && index < RATE_UNITS.length - 1) {
    scaled /= 1024;
    index += 1;
  }
  return { value: n < 0 ? -scaled : scaled, unit: RATE_UNITS[index] };
}

/**
 * "1.4 MB/s" with locale-aware decimals. Whole bytes and values >= 10 render
 * without a fraction — an axis tick reading "1,024 B/s" needs no ".0".
 */
export function formatRate(value, format) {
  const scaled = scaleRate(value);
  if (!scaled) return "—";
  const fractionDigits =
    scaled.unit === RATE_UNITS[0] || Math.abs(scaled.value) >= 10 ? 0 : 1;
  const number = format.number(scaled.value, {
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  });
  return `${number} ${scaled.unit}`;
}

/**
 * Human byte size — "212 MB". Locale-formatted by the caller, so pass a
 * next-intl formatter. Returns null for anything that is not a byte count, so
 * a missing value renders as a dash rather than "NaN B".
 */
export function formatBytes(bytes, format) {
  // Nullish and "" are rejected before Number() sees them, because it turns all
  // three into 0 — finite, non-negative, and therefore "0 B". So "we have not
  // measured this yet" rendered as "this is empty": the sites list showed 0 B on
  // every row of a server whose sizes were all null, and its own
  // "Not measured" branch could never be reached. The Number.isFinite guard
  // below was written to catch exactly this and cannot.
  if (bytes === null || bytes === undefined || bytes === "") return null;

  const n = Number(bytes);
  if (!Number.isFinite(n) || n < 0) return null;
  const units = ["B", "KB", "MB", "GB"];
  let value = n;
  let i = 0;
  while (value >= 1024 && i < units.length - 1) {
    value /= 1024;
    i += 1;
  }
  const digits = i === 0 || value >= 10 ? 0 : 1;
  return `${format.number(value, {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  })} ${units[i]}`;
}
