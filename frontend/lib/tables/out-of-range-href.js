/**
 * Where a page that no longer exists should send the reader.
 *
 * To the end of the list, not to page 1: deleting the only row on page 3 of 3
 * belongs on page 2, and dropping someone at the start of a long list after a
 * delete is a second surprise on top of the first.
 *
 * Filters are kept — they are what the reader chose. Only `page` moves.
 */
export function outOfRangeHref(searchParams, lastPage = 1) {
  const next = new URLSearchParams(searchParams);
  next.delete("page");
  // Page 1 is the bare URL, matching how the pager writes it everywhere else.
  if (lastPage > 1) next.set("page", String(lastPage));
  return next.size ? `?${next}` : "?";
}
