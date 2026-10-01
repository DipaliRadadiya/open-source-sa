/**
 * The `aria-sort` value for a column, from the URL's `?sort=`: the bare key is
 * ascending, `-key` descending, absent means the API's own order. Shared because
 * the sort button inside the `<th>` cannot see the state the `<th>` needs.
 */
export function sortDirection(current, col) {
  if (current === col) return "ascending";
  if (current === `-${col}`) return "descending";
  return "none";
}
