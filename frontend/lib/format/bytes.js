const RATE_UNITS = ["B/s", "KB/s", "MB/s", "GB/s", "TB/s"];

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

export function formatBytes(bytes, format) {
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

const FILE_UNITS = ["B", "KB", "MB", "GB", "TB"];

export function formatFileSize(bytes, format) {
  if (bytes === null || bytes === undefined || bytes === "") return null;

  const n = Number(bytes);
  if (!Number.isFinite(n) || n < 0) return null;
  let value = n;
  let i = 0;
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

const SIZE_TEXT = /^\s*(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d+))?\s*([kKMGTP]?i?B)\s*$/;
const DOCKER_POWERS = { B: 0, kB: 1, KB: 1, MB: 2, GB: 3, TB: 4, PB: 5 };

export function localizeSizeText(text, format) {
  if (typeof text !== "string") return text;
  const match = SIZE_TEXT.exec(text);
  if (!match) return text;
  const [, whole, fraction = "", unit] = match;
  const value = Number(`${whole.replaceAll(",", "")}.${fraction || "0"}`);
  const digits = fraction.length;
  return `${format.number(value, { minimumFractionDigits: digits, maximumFractionDigits: digits })} ${unit}`;
}

export function formatDockerSize(text, format) {
  if (typeof text !== "string") return null;
  const match = SIZE_TEXT.exec(text);
  const power = match ? DOCKER_POWERS[match[3]] : undefined;
  if (power === undefined) return text || null;
  const value = Number(`${match[1].replaceAll(",", "")}.${match[2] || "0"}`);
  return formatFileSize(value * 1000 ** power, format);
}
