const UNITS = { b: 1, kb: 1024, mb: 1024 ** 2, gb: 1024 ** 3 };

export function memoryBytes(value) {
  const match = /^(\d+)(kb|mb|gb|b)?$/i.exec(String(value ?? "").trim());
  if (!match) return null;
  return Number(match[1]) * UNITS[(match[2] ?? "b").toLowerCase()];
}

export function memoryInput(raw) {
  const bytes = memoryBytes(raw);
  if (bytes === null) return raw ?? "0";
  if (bytes === 0) return "0";
  for (const [unit, size] of [["gb", UNITS.gb], ["mb", UNITS.mb], ["kb", UNITS.kb]]) {
    if (bytes % size === 0) return `${bytes / size}${unit}`;
  }
  return String(bytes);
}
