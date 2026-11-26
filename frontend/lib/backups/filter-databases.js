export function filterDatabases(rows, linked) {
  const list = rows.map((row) => ({ id: row.database.id, name: row.database.name }));
  if (linked && !list.some((row) => String(row.id) === String(linked.id))) {
    list.push({ id: linked.id, name: linked.name ?? `#${linked.id}` });
  }
  return list;
}
