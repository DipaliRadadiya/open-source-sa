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

/**
 * A mode's permission digits and the optional special-bits digit in front.
 * `find -printf %m` prints four digits when setuid/setgid/sticky is set
 * (e.g. `1777`), so every helper must accept both lengths.
 *
 * Returns null for a non-mode (e.g. an in-progress custom entry).
 */
export function modeParts(mode) {
  const match = /^([0-7]?)([0-7]{3})$/.exec(String(mode ?? ""));
  return match ? { special: match[1], permissions: match[2] } : null;
}

// { owner, group, other } token arrays, or null for anything that isn't a
// mode (an in-progress custom entry). Special bits do not change what the
// three audiences may do, so they are not described here.
export function describeMode(mode) {
  const parts = modeParts(mode);
  if (!parts) return null;
  const [owner, group, other] = parts.permissions.split("");
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
 * Uppercase when execute is not set, as `ls` does.
 */
function withSpecialBit(triad, marker) {
  return triad.slice(0, 2) + (triad[2] === "x" ? marker : marker.toUpperCase());
}

/**
 * A mode as `ls -l` writes it (`drwxr-xr-x` rather than `755`). Accepts
 * 3- and 4-digit modes.
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

// Whether one audience holds one permission. Indexes the permission digits,
// not the raw string, which may start with a special-bits digit.
export function hasPermission(mode, audience, permission) {
  const parts = modeParts(mode);
  const digit = Number(parts?.permissions[AUDIENCES.indexOf(audience)] ?? 0);
  return Boolean(digit & PERMISSION_BITS[permission]);
}

/** The same mode with one permission set or cleared. */
export function withPermission(mode, audience, permission, on) {
  const parts = modeParts(mode);
  const digits = (parts?.permissions ?? "000").split("").map(Number);
  const index = AUDIENCES.indexOf(audience);
  digits[index] = on
    ? digits[index] | PERMISSION_BITS[permission]
    : digits[index] & ~PERMISSION_BITS[permission];
  // The special-bits digit is preserved: the checkboxes cannot express it,
  // and dropping it would silently change the mode.
  return `${parts?.special ?? ""}${digits.join("")}`;
}

// "Others" can write (bit 2): any account on the server could modify the
// file. Almost never intentional, so flagged wherever a mode is shown.
export function isWorldWritable(mode) {
  // Reads the last permission digit, so four-digit modes like `1777` count.
  const parts = modeParts(mode);
  return parts ? /[2367]/.test(parts.permissions[2]) : false;
}
