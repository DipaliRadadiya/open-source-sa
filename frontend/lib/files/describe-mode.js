// One octal digit -> which of read/write/execute it grants (the standard
// 4/2/1 bitmask) — language-neutral tokens, translated by the caller.
function digitPermissions(digit) {
  const n = Number(digit);
  const tokens = [];
  if (n & 4) tokens.push("read");
  if (n & 2) tokens.push("write");
  if (n & 1) tokens.push("execute");
  return tokens;
}

// { owner, group, other } token arrays for a 3-digit octal mode, or null for
// anything that isn't exactly 3 octal digits (an in-progress custom entry).
export function describeMode(mode) {
  if (!/^[0-7]{3}$/.test(mode)) return null;
  const [owner, group, other] = mode.split("");
  return {
    owner: digitPermissions(owner),
    group: digitPermissions(group),
    other: digitPermissions(other),
  };
}

// One octal digit -> its rwx triad, indexed by value.
const TRIADS = ["---", "--x", "-w-", "-wx", "r--", "r-x", "rw-", "rwx"];

/**
 * Replaces a triad's execute character with the setuid/setgid/sticky marker.
 *
 * Uppercase when the execute bit is *not* set, which is `ls`'s way of saying
 * the special bit is on but does nothing — the distinction matters, because a
 * lowercase `s` and an uppercase `S` are a working setuid binary and a broken
 * one.
 */
function withSpecialBit(triad, marker) {
  return triad.slice(0, 2) + (triad[2] === "x" ? marker : marker.toUpperCase());
}

/**
 * A mode as `ls -l` writes it — `drwxr-xr-x` rather than `755`.
 *
 * Octal is exact but has to be decoded in your head; the symbolic form is what
 * anyone who has used a shell already reads at a glance, and it shows *which*
 * of read/write/execute is missing rather than only that something is.
 *
 * Accepts 4-digit modes as well as 3: `find -printf %m` emits the leading
 * digit whenever setuid, setgid or the sticky bit is set, so a 3-digit-only
 * reading silently gave up on exactly the files whose permissions are most
 * worth looking at.
 */
export function symbolicMode(mode, type) {
  if (!/^[0-7]{3,4}$/.test(String(mode ?? ""))) return null;

  const digits = String(mode).padStart(4, "0");
  const special = Number(digits[0]);
  const triads = digits
    .slice(1)
    .split("")
    .map((digit) => TRIADS[Number(digit)]);

  if (special & 4) triads[0] = withSpecialBit(triads[0], "s");
  if (special & 2) triads[1] = withSpecialBit(triads[1], "s");
  if (special & 1) triads[2] = withSpecialBit(triads[2], "t");

  const prefix = type === "dir" ? "d" : type === "symlink" ? "l" : "-";

  return prefix + triads.join("");
}

// Which bit each permission is worth, in the standard 4/2/1 mask.
export const PERMISSION_BITS = { read: 4, write: 2, execute: 1 };

// The three audiences a mode covers, in the order the digits appear.
export const AUDIENCES = ["owner", "group", "other"];

// Whether one audience holds one permission in this mode.
export function hasPermission(mode, audience, permission) {
  const digit = Number(mode?.[AUDIENCES.indexOf(audience)] ?? 0);
  return Boolean(digit & PERMISSION_BITS[permission]);
}

/**
 * The same mode with one box ticked or cleared.
 *
 * Editing the digits directly is what lets the dialog offer nine plain
 * checkboxes instead of asking for octal: every combination is reachable, and
 * an invalid one cannot be typed.
 */
export function withPermission(mode, audience, permission, on) {
  const digits = (/^[0-7]{3}$/.test(mode) ? mode : "000").split("").map(Number);
  const index = AUDIENCES.indexOf(audience);
  digits[index] = on
    ? digits[index] | PERMISSION_BITS[permission]
    : digits[index] & ~PERMISSION_BITS[permission];
  return digits.join("");
}

// The "others" digit granting write (bit 2) — anyone with a shell account on
// the box, not just the site's own user, could modify the file. Almost never
// intentional, worth flagging wherever a mode is shown rather than only
// discoverable by opening the Permissions dialog.
export function isWorldWritable(mode) {
  return /^[0-7]{2}[2367]$/.test(mode);
}
