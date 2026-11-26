export const JAIL_FIELDS = ["maxretry", "findtime", "bantime"];

export const APP_BAN_PRESETS = [600, 3600, 86400, 604800];

function lineFor(key) {
  return new RegExp(`^([ \\t]*${key}[ \\t]*=[ \\t]*)([^\\n#;]*?)([ \\t]*)$`, "m");
}

export function readJailValue(jail, key) {
  const match = lineFor(key).exec(jail ?? "");
  return match ? match[2] : null;
}

export function writeJailValue(jail, key, value) {
  return jail.replace(lineFor(key), (_, head, _old, tail) => `${head}${value}${tail}`);
}

export function wholeNumberIn(value, min, max) {
  return /^\d+$/.test(String(value)) && Number(value) >= min && Number(value) <= max;
}
