/**
 * Log lines as text, without terminal colour codes.
 *
 * Node apps (Uptime Kuma, n8n) colour their output, and the file keeps the
 * escape sequences: every line read `[36m2026-09-29T06:22:05Z[0m [[38;5;117mDB[0m]`.
 * Stripped where the lines arrive, so search, the severity filter and Copy
 * all see the same text the reader does.
 */
// ESC [ … final byte — colours, cursor moves, erase-line. Built from a string
// so the escape character is spelled out rather than hidden in a literal.
const ANSI = new RegExp(`${String.fromCharCode(27)}\\[[0-9;?]*[ -/]*[@-~]`, "g");

export function cleanLines(lines) {
  return (lines ?? []).map((line) => (typeof line === "string" ? line.replace(ANSI, "") : line));
}
