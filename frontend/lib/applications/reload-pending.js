// A settings save the API stored and tested, but the web server did not reload
// (`WebServerReloadFailedException`). Remembered per browser so the warning
// survives navigation and reloads; the API keeps no such marker.

export const RELOAD_PENDING_COOKIE = "reload_pending";
export const RELOAD_PENDING_EVENT = "reload-pending-change";

const MAX_RECORDS = 20;
// Encoded bytes, well under the 4096 a browser keeps per cookie with its attributes.
const MAX_BYTES = 3500;
const MAX_AGE = 60 * 60 * 24 * 30;
const ID = /^[A-Za-z0-9_-]{1,64}$/;
const REFERENCE = /^[A-Za-z0-9-]{1,64}$/;
const TOKEN = /^[a-z0-9]{1,32}$/;

// Only these units serve sites, and only these verbs load the config from disk.
// Not start: on a unit that is already running it does nothing.
const WEB_SERVERS = ["nginx", "apache", "openlitespeed"];
const APPLYING_ACTIONS = ["restart", "reload"];

// Only the exact code: any other 500, a timeout or a 422 left nothing saved.
export function isReloadFailed(error) {
  return error?.response?.status === 500 && error.response.data?.code === "web_server_reload_failed";
}

export function reloadReference(error) {
  const reference = error?.response?.data?.reference;
  return typeof reference === "string" && REFERENCE.test(reference.trim()) ? reference.trim() : "";
}

export function clearsReloadPending(key, action) {
  return WEB_SERVERS.includes(key) && APPLYING_ACTIONS.includes(action);
}

// Ids, reference and a token only: never settings, names or credentials.
// Anything malformed, oversized or extra is dropped rather than trusted.
export function parseReloadPending(raw) {
  if (typeof raw !== "string" || !raw || raw.length > MAX_BYTES) return [];
  let value;
  try {
    value = JSON.parse(raw);
  } catch {
    return [];
  }
  if (!Array.isArray(value)) return [];
  const records = [];
  for (const entry of value.slice(0, MAX_RECORDS)) {
    if (!entry || typeof entry !== "object" || Array.isArray(entry)) continue;
    const { u, a, r, t } = entry;
    if (typeof u !== "string" || !ID.test(u)) continue;
    if (typeof a !== "string" || !ID.test(a)) continue;
    if (typeof t !== "string" || !TOKEN.test(t)) continue;
    if (r !== "" && (typeof r !== "string" || !REFERENCE.test(r))) continue;
    records.push({ u, a, r, t });
  }
  return records;
}

// Drops the oldest records until the encoded value fits, so a write never
// silently fails and loses the newest warning.
export function serializeReloadPending(records) {
  let kept = records.slice(-MAX_RECORDS).map(({ u, a, r, t }) => ({ u, a, r, t }));
  let value = JSON.stringify(kept);
  while (kept.length > 1 && encodeURIComponent(value).length > MAX_BYTES) {
    kept = kept.slice(1);
    value = JSON.stringify(kept);
  }
  return value;
}

export function recordsForUser(records, userId) {
  const user = String(userId ?? "");
  return user ? records.filter((record) => record.u === user) : [];
}

// One record per user and application; the newest failure replaces the older one.
export function withReloadPending(records, { userId, appId, reference = "", token }) {
  const u = String(userId ?? "");
  const a = String(appId ?? "");
  const r = REFERENCE.test(reference) ? reference : "";
  if (!ID.test(u) || !ID.test(a) || !TOKEN.test(token ?? "")) return records;
  const rest = records.filter((record) => !(record.u === u && record.a === a));
  const next = [...rest, { u, a, r, t: token }];
  return next.slice(-MAX_RECORDS);
}

export function withoutTokens(records, tokens) {
  return records.filter((record) => !tokens.has(record.t));
}

export function newReloadToken() {
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`;
}

export function readReloadPending() {
  if (typeof document === "undefined") return [];
  const prefix = `${RELOAD_PENDING_COOKIE}=`;
  const pair = document.cookie.split("; ").find((part) => part.startsWith(prefix));
  if (!pair) return [];
  try {
    return parseReloadPending(decodeURIComponent(pair.slice(prefix.length)));
  } catch {
    return [];
  }
}

export function writeReloadPending(records) {
  if (typeof document === "undefined") return;
  const secure = window.location.protocol === "https:" ? "; secure" : "";
  document.cookie = records.length
    ? `${RELOAD_PENDING_COOKIE}=${encodeURIComponent(serializeReloadPending(records))}; path=/; max-age=${MAX_AGE}; samesite=lax${secure}`
    : `${RELOAD_PENDING_COOKIE}=; path=/; max-age=0; samesite=lax${secure}`;
  window.dispatchEvent(new Event(RELOAD_PENDING_EVENT));
}

// Run around a service action. Clears only what was pending before the request
// went out, so a failure saved while it ran survives.
export async function settleReloadPending(key, action, request) {
  const before = clearsReloadPending(key, action) ? new Set(readReloadPending().map((record) => record.t)) : null;
  const response = await request();
  const status = response?.status;
  if (before?.size && typeof status === "number" && status >= 200 && status < 300) {
    writeReloadPending(withoutTokens(readReloadPending(), before));
  }
  return response;
}
