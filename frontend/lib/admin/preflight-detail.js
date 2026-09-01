/**
 * The preflight `detail` is an English sentence the backend builds by hand —
 * `UpdatePreflight::freeDisk()` returns `$freeMb.'MB free, '.$required.'MB
 * required'` — so it reached Spanish and Hindi panels untranslated, and in raw
 * megabytes: "261887MB free" is 256 GB written the one way nobody reads it.
 *
 * Parsing a sentence is normally the wrong move, but this one has a fixed shape
 * we can read in the backend source, and anything that does not match falls
 * through to the original string. The proper fix is the API sending numbers;
 * until then this is the only place that knows the sentence exists.
 */
/**
 * The memory check adds a swap term — `700MB available + 2400MB swap, 2560MB
 * required` — because available memory alone answers the wrong question on a
 * box whose installer added a swapfile precisely so the build could finish.
 * It is optional in the pattern rather than a second regex: the disk check
 * has no such term and both must keep parsing.
 */
const SIZE = /^(\d+)MB (free|available)(?: \+ (\d+)MB swap)?, (\d+)MB required$/;

export function parseSizeDetail(detail) {
  const match = SIZE.exec(String(detail ?? "").trim());
  if (!match) return null;
  const swapMb = match[3] === undefined ? null : Number(match[3]);
  return {
    // The headline figure is what the build can actually reach, so swap is
    // added in rather than reported beside it — a 700MB box with 2.4GB of swap
    // is not a 700MB box for this purpose.
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
 * Megabytes → the unit a person would say out loud, plus how many decimals that
 * unit deserves. 255.75 GB is "256 GB"; 1.5 GB stays "1.5 GB", because rounding
 * that one to "2 GB" would claim more headroom than the server has.
 */
export function megabytes(mb) {
  if (!Number.isFinite(mb) || mb < 0) return null;
  if (mb < 1024) return { value: mb, unit: "MB", maximumFractionDigits: 0 };
  const gb = mb / 1024;
  return { value: gb, unit: "GB", maximumFractionDigits: gb >= 10 ? 0 : 1 };
}
