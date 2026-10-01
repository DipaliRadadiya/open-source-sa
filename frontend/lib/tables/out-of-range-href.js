/**
 * Where an out-of-range page redirects: the last page, not page 1. Filters
 * are kept; only `page` changes.
 */
export function outOfRangeHref(searchParams, lastPage = 1) {
  const next = new URLSearchParams(searchParams);
  next.delete("page");
  // Page 1 is the bare URL, matching the pager.
  if (lastPage > 1) next.set("page", String(lastPage));
  return next.size ? `?${next}` : "?";
}
