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
  const units = ["B", "KB", "MB", "GB", "TB"];
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

// "KB" like every other size in the panel (Krishna, 24 Nov: "19.9 kB" beside "5.0 KB" read as two systems).
const FILE_UNITS = ["B", "KB", "MB", "GB", "TB"];

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
  // Step on the rounded figure, so 999,960 bytes is "1.0 MB", never "1,000.0 KB".
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

// "1.6 GB", "1,000 MB" (the API's Bytes::human) or Docker's "1.618MB": a number and a unit.
const SIZE_TEXT = /^\s*(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d+))?\s*([kKMGTP]?i?B)\s*$/;
const DOCKER_POWERS = { B: 0, kB: 1, KB: 1, MB: 2, GB: 3, TB: 4, PB: 5 };

/**
 * A size the API already rendered, re-rendered in the reader's number format: "1.6 GB" stays
 * "1.6 GB" in English and becomes "1,6 GB" in German. The digits after the point are kept.
 * Anything that is not a plain size (a dash, a sentence) comes back unchanged.
 */
export function localizeSizeText(text, format) {
  if (typeof text !== "string") return text;
  const match = SIZE_TEXT.exec(text);
  if (!match) return text;
  const [, whole, fraction = "", unit] = match;
  const value = Number(`${whole.replaceAll(",", "")}.${fraction || "0"}`);
  const digits = fraction.length;
  return `${format.number(value, { minimumFractionDigits: digits, maximumFractionDigits: digits })} ${unit}`;
}

/**
 * Docker's own size text (`docker system df`: "1.618MB", "0B", decimal units) as a file size:
 * one decimal and the reader's number format, the same as the file manager shows.
 */
export function formatDockerSize(text, format) {
  if (typeof text !== "string") return null;
  const match = SIZE_TEXT.exec(text);
  const power = match ? DOCKER_POWERS[match[3]] : undefined;
  if (power === undefined) return text || null;
  const value = Number(`${match[1].replaceAll(",", "")}.${match[2] || "0"}`);
  return formatFileSize(value * 1000 ** power, format);
}
