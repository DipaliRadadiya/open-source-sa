function digitPermissions(digit) {
  const n = Number(digit);
  const tokens = [];
  if (n & 4) tokens.push("read");
  if (n & 2) tokens.push("write");
  if (n & 1) tokens.push("execute");
  return tokens;
}

// find -printf %m prints 4 digits when setuid/setgid/sticky is set.
export function modeParts(mode) {
  const match = /^([0-7]?)([0-7]{3})$/.exec(String(mode ?? ""));
  return match ? { special: match[1], permissions: match[2] } : null;
}

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

const TRIADS = ["---", "--x", "-w-", "-wx", "r--", "r-x", "rw-", "rwx"];

function withSpecialBit(triad, marker) {
  return triad.slice(0, 2) + (triad[2] === "x" ? marker : marker.toUpperCase());
}

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

export const PERMISSION_BITS = { read: 4, write: 2, execute: 1 };

export const AUDIENCES = ["owner", "group", "other"];

export function hasPermission(mode, audience, permission) {
  const parts = modeParts(mode);
  const digit = Number(parts?.permissions[AUDIENCES.indexOf(audience)] ?? 0);
  return Boolean(digit & PERMISSION_BITS[permission]);
}

export function withPermission(mode, audience, permission, on) {
  const parts = modeParts(mode);
  const digits = (parts?.permissions ?? "000").split("").map(Number);
  const index = AUDIENCES.indexOf(audience);
  digits[index] = on
    ? digits[index] | PERMISSION_BITS[permission]
    : digits[index] & ~PERMISSION_BITS[permission];
  // Keep the special-bits digit; the checkboxes can't show it.
  return `${parts?.special ?? ""}${digits.join("")}`;
}

export function isWorldWritable(mode) {
  const parts = modeParts(mode);
  return parts ? /[2367]/.test(parts.permissions[2]) : false;
}
