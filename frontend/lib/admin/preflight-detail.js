// Parses the backend's English `UpdatePreflight` detail; keep in step with its format.
// Unmatched text falls back to the raw string. Only the memory check sends swap.
const SIZE = /^(\d+)MB (free|available)(?: \+ (\d+)MB swap)?, (\d+)MB required$/;

export function parseSizeDetail(detail) {
  const match = SIZE.exec(String(detail ?? "").trim());
  if (!match) return null;
  const swapMb = match[3] === undefined ? null : Number(match[3]);
  return {
    // Swap counts toward what the build can use.
    haveMb: Number(match[1]) + (swapMb ?? 0),
    kind: match[2],
    swapMb,
    needMb: Number(match[4]),
  };
}

/** Every check can report this when it cannot inspect the thing it checks. */
export function isUnknownDetail(detail) {
  return String(detail ?? "").trim() === "unknown";
}

/** Under 10 GB keeps one decimal so rounding never overstates headroom. */
export function megabytes(mb) {
  if (!Number.isFinite(mb) || mb < 0) return null;
  if (mb < 1024) return { value: mb, unit: "MB", maximumFractionDigits: 0 };
  const gb = mb / 1024;
  return { value: gb, unit: "GB", maximumFractionDigits: gb >= 10 ? 0 : 1 };
}
