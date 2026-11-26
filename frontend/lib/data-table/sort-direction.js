export function sortDirection(current, col) {
  if (current === col) return "ascending";
  if (current === `-${col}`) return "descending";
  return "none";
}
