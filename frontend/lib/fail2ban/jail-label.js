// Known jails are named by id per language; anything else keeps the server's English label.
export function jailLabel(t, jail) {
  const key = `jails.names.${jail?.name}`;
  return jail?.name && t.has(key) ? t(key) : (jail?.label ?? jail?.name ?? "");
}
