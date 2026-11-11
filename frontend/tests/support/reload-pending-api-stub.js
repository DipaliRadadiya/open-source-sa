// Stands in for lib/api/applications, lib/api/files and lib/api/client in the
// saved-but-not-live tests. Each test sets globalThis.__apiRespond.
function respond(name, payload) {
  (globalThis.__apiCalls ??= []).push({ name, payload });
  return globalThis.__apiRespond ? globalThis.__apiRespond(name, payload) : Promise.resolve({ status: 200, data: {} });
}

export const updateWebRoot = (_id, webRoot) => respond("webRoot", { web_root: webRoot });
export const updateApplicationWaf = (_id, payload) => respond("waf", payload);
export const updateApplicationBotBlocker = (_id, payload) => respond("bots", payload);
export const updateApplicationSecurity = (_id, payload) => respond("security", payload);
export const listFiles = async () => ({ status: 200, data: { files: [] } });
export const api = { put: (url, body) => respond("put", { url, ...body }) };
