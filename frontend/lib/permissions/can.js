export function findPermission(catalog, name, level = "server") {
  return (catalog ?? []).find(
    (p) => p.name === name && (!level || p.level === level),
  );
}

export function can(catalog, name, action = "view", level = "server") {
  const p = findPermission(catalog, name, level);
  if (!p) return false;
  if (action === "view") return Boolean(p.permissions?.view || p.permissions?.manage);
  return Boolean(p.permissions?.manage);
}
