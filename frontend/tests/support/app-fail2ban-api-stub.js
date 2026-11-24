// Stands in for lib/api/applications in the app Fail2ban tests. The component bundle has
// its own copy of this module, so the record lives on globalThis.
globalThis.__fail2banSaved = globalThis.__fail2banSaved ?? [];

export async function saveApplicationFail2ban(id, draft, options) {
  globalThis.__fail2banSaved.push({ id, ...draft, options });
  return { data: {} };
}
export async function deleteApplicationFail2ban() {
  return { data: {} };
}
