/**
 * Parses the preflight `detail`, an English sentence built by the backend
 * (`UpdatePreflight`), so it can be translated and shown in readable units.
 * Keep in step with the backend format; unmatched text falls back to the raw
 * string. The swap term is optional: only the memory check sends it.
 */
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

/**
 * Megabytes → MB or GB with suitable decimals. Under 10 GB keeps one decimal
 * so rounding never overstates headroom.
 */
export function megabytes(mb) {
  if (!Number.isFinite(mb) || mb < 0) return null;
  if (mb < 1024) return { value: mb, unit: "MB", maximumFractionDigits: 0 };
  const gb = mb / 1024;
  return { value: gb, unit: "GB", maximumFractionDigits: gb >= 10 ? 0 : 1 };
}
