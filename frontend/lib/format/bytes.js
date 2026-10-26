// Byte-rate formatting for the metrics charts. Units stay untranslated; pass a
// next-intl formatter (useFormatter/getFormatter) as `format` for the number.

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

/** Whole bytes and values >= 10 render without a fraction. */
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

/** Null for anything that is not a byte count, so a missing value is not "NaN B". */
export function formatBytes(bytes, format) {
  // Reject nullish and "" first: Number() turns them into 0, so "not measured"
  // would render as "0 B".
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

const FILE_UNITS = ["B", "kB", "MB", "GB", "TB"];

/**
 * File sizes for the file manager: steps of 1000 with one decimal, as Ubuntu's and
 * macOS's file managers show them, so a file reads the same here as on the user's
 * computer (formatBytes steps by 1024, which showed a 243.6 MB file as 232 MB).
 */
export function formatFileSize(bytes, format) {
  if (bytes === null || bytes === undefined || bytes === "") return null;

  const n = Number(bytes);
  if (!Number.isFinite(n) || n < 0) return null;
  let value = n;
  let i = 0;
  // Step on the rounded figure, so 999,960 bytes is "1.0 MB", never "1,000.0 kB".
  const shown = () => (i === 0 ? Math.round(value) : Math.round(value * 10) / 10);
  while (shown() >= 1000 && i < FILE_UNITS.length - 1) {
    value /= 1000;
    i += 1;
  }
  const digits = i === 0 ? 0 : 1;
  return `${format.number(value, {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  })} ${FILE_UNITS[i]}`;
}
