// The last page, not page 1; filters are kept.
export function outOfRangeHref(searchParams, lastPage = 1) {
  const next = new URLSearchParams(searchParams);
  next.delete("page");
  // Page 1 is the bare URL, matching the pager.
  if (lastPage > 1) next.set("page", String(lastPage));
  return next.size ? `?${next}` : "?";
}
