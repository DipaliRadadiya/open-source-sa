// Permission helpers over the caller's granted catalog (from getPermissions()).
// Each catalog item is { level, name, ..., permissions: { view, manage } }.

export function findPermission(catalog, name, level = "server") {
  return (catalog ?? []).find(
    (p) => p.name === name && (!level || p.level === level),
  );
}

// True when the caller holds `action` (view | manage) on the named feature.
// `manage` implies `view`.
// `level` matters for the app_* features: an application permission and a
// server one can share neither name nor meaning ("this site's domains" vs
// "every application"), so the lookup has to say which it wants.
export function can(catalog, name, action = "view", level = "server") {
  const p = findPermission(catalog, name, level);
  if (!p) return false;
  if (action === "view") return Boolean(p.permissions?.view || p.permissions?.manage);
  return Boolean(p.permissions?.manage);
}
