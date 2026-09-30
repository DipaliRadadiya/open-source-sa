// Jail names come from the server in English ("Repeat offenders"). Known jails
// are named by their fixed id in each language; anything else keeps the
// server's label.
export function jailLabel(t, jail) {
  const key = `jails.names.${jail?.name}`;
  return jail?.name && t.has(key) ? t(key) : (jail?.label ?? jail?.name ?? "");
}
