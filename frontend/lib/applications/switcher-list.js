// The switcher's rows. Unsearched, the application you are on comes first, even when it is past the
// first page (Krishna, 3 Nov: on the 21st of 33 nothing was ticked, so you could not see where you were).
// A search shows the matches as the server sent them. Nothing is added to an empty list, so
// loading and retrying still read as loading.
export function switcherRows(applications, current, query) {
  const list = Array.isArray(applications) ? applications : [];
  if (query || !current || !list.length) return list;
  const same = (item) => String(item.id) === String(current.id);
  return [list.find(same) ?? current, ...list.filter((item) => !same(item))];
}
