/**
 * The `aria-sort` value for a column, from the `?sort=` the URL is carrying.
 *
 * The bare key is ascending, `-key` descending, absent means the API's own
 * order. Two components need this and neither can ask the other: the button
 * lives inside the `<th>` that DataTable renders, so the element carrying
 * `aria-sort` cannot see the state of the element that sets it. One function
 * rather than two readings of the same parameter.
 */
export function sortDirection(current, col) {
  if (current === col) return "ascending";
  if (current === `-${col}`) return "descending";
  return "none";
}
