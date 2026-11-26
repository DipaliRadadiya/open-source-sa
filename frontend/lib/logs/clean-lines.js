const ANSI = new RegExp(`${String.fromCharCode(27)}\\[[0-9;?]*[ -/]*[@-~]`, "g");

export function cleanLines(lines) {
  return (lines ?? []).map((line) => (typeof line === "string" ? line.replace(ANSI, "") : line));
}
