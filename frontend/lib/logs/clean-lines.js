// Node apps keep colour codes in the file; stripped so search, filter and Copy see what the reader sees.
// ESC [ … final byte. Built from a string so the escape character is explicit.
const ANSI = new RegExp(`${String.fromCharCode(27)}\\[[0-9;?]*[ -/]*[@-~]`, "g");

export function cleanLines(lines) {
  return (lines ?? []).map((line) => (typeof line === "string" ? line.replace(ANSI, "") : line));
}
