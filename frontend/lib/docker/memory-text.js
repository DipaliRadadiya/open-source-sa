// Docker's memory notation ("1g", "512m") in the words a reader expects ("1 GB", "512 MB").
// Anything else comes back as it was.
const UNITS = { k: "KB", m: "MB", g: "GB", t: "TB" };

export function dockerMemoryText(value) {
  const match = /^\s*(\d+(?:\.\d+)?)\s*([kmgt])b?\s*$/i.exec(String(value ?? ""));
  if (!match) return value;
  return `${match[1]} ${UNITS[match[2].toLowerCase()]}`;
}
