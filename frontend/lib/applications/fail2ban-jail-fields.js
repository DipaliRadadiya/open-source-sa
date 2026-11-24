/**
 * The three numbers people change in a site's jail, read from and written back into
 * its text, so the plain fields and the file editor never disagree.
 */
export const JAIL_FIELDS = ["maxretry", "findtime", "bantime"];

// Ban lengths offered for one site; the saved value is added when it is not one of them.
export const APP_BAN_PRESETS = [600, 3600, 86400, 604800];

function lineFor(key) {
  return new RegExp(`^([ \\t]*${key}[ \\t]*=[ \\t]*)([^\\n#;]*?)([ \\t]*)$`, "m");
}

/** The value as typed in the file, or null when the jail does not set it. */
export function readJailValue(jail, key) {
  const match = lineFor(key).exec(jail ?? "");
  return match ? match[2] : null;
}

/** Only the value changes; the key's spacing and every other line stay as they were. */
export function writeJailValue(jail, key, value) {
  return jail.replace(lineFor(key), (_, head, _old, tail) => `${head}${value}${tail}`);
}

export function wholeNumberIn(value, min, max) {
  return /^\d+$/.test(String(value)) && Number(value) >= min && Number(value) <= max;
}
