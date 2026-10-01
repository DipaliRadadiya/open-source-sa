/**
 * Log lines without terminal colour codes (Node apps keep escape sequences in
 * the file). Stripped on arrival so search, the severity filter and Copy see
 * what the reader sees.
 */
// ESC [ … final byte: colours, cursor moves, erase-line. Built from a string
// so the escape character is explicit.
const ANSI = new RegExp(`${String.fromCharCode(27)}\\[[0-9;?]*[ -/]*[@-~]`, "g");

export function cleanLines(lines) {
  return (lines ?? []).map((line) => (typeof line === "string" ? line.replace(ANSI, "") : line));
}
