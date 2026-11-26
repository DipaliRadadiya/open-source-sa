export function jailLabel(t, jail) {
  const key = `jails.names.${jail?.name}`;
  return jail?.name && t.has(key) ? t(key) : (jail?.label ?? jail?.name ?? "");
}
