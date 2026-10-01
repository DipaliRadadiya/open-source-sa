// `?sort=key` is ascending, `-key` descending, absent the API's order. Shared because
// the sort button cannot see the state the `<th>` needs.
export function sortDirection(current, col) {
  if (current === col) return "ascending";
  if (current === `-${col}`) return "descending";
  return "none";
}
