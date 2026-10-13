// Redis reports maxmemory in bytes ("268435456"); the API accepts "256mb". Shown in the
// largest whole unit, so people read "256mb", not a nine-digit number.
const UNITS = { b: 1, kb: 1024, mb: 1024 ** 2, gb: 1024 ** 3 };

/** Bytes for "0", "268435456" or "256mb"; null when unreadable. */
export function memoryBytes(value) {
  const match = /^(\d+)(kb|mb|gb|b)?$/i.exec(String(value ?? "").trim());
  if (!match) return null;
  return Number(match[1]) * UNITS[(match[2] ?? "b").toLowerCase()];
}

/** The form value for a stored limit: "256mb", "1gb", or "0" for no limit. */
export function memoryInput(raw) {
  const bytes = memoryBytes(raw);
  if (bytes === null) return raw ?? "0";
  if (bytes === 0) return "0";
  for (const [unit, size] of [["gb", UNITS.gb], ["mb", UNITS.mb], ["kb", UNITS.kb]]) {
    if (bytes % size === 0) return `${bytes / size}${unit}`;
  }
  return String(bytes);
}
