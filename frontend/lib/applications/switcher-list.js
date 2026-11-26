export function switcherRows(applications, current, query) {
  const list = Array.isArray(applications) ? applications : [];
  if (query || !current || !list.length) return list;
  const same = (item) => String(item.id) === String(current.id);
  return [list.find(same) ?? current, ...list.filter((item) => !same(item))];
}
