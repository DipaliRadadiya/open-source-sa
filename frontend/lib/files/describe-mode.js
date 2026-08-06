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

// The "others" digit granting write (bit 2) — anyone with a shell account on
// the box, not just the site's own user, could modify the file. Almost never
// intentional, worth flagging wherever a mode is shown rather than only
// discoverable by opening the Permissions dialog.
export function isWorldWritable(mode) {
  return /^[0-7]{2}[2367]$/.test(mode);
}
