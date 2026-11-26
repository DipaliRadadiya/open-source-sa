export function outOfRangeHref(searchParams, lastPage = 1) {
  const next = new URLSearchParams(searchParams);
  next.delete("page");
  if (lastPage > 1) next.set("page", String(lastPage));
  return next.size ? `?${next}` : "?";
}
