import { api } from "@/lib/api/client";

/** Engine capability list — also the poll target while an install runs. */
export function getEngines({ signal } = {}) {
  return api.get("/databases/engines", { signal });
}

// 202, then poll `getEngines`. An engine already present returns 200 with `queued: false`.
export function installEngine(engine) {
  return api.post(`/databases/engines/${encodeURIComponent(engine)}`);
}

// For naming them only: the delete call sends a flag and the API resolves the
// list itself.
export function getDatabasesForApplication(applicationId, { signal } = {}) {
  return api.get("/databases", {
    params: { "filter[application_id]": applicationId, per_page: 100 },
    signal,
  });
}

export function createDatabase(payload) {
  return api.post("/databases", payload);
}

// `application_id` is always sent: an absent key is a 422, not a detach (null).
// Bookkeeping only; nothing rewrites `wp-config.php` or `.env`.
export function attachDatabase(databaseId, applicationId) {
  return api.put(`/databases/${databaseId}/application`, {
    application_id:
      applicationId === null ||
      applicationId === undefined ||
      applicationId === ""
        ? null
        : Number(applicationId),
  });
}

/** Drops the database AND its users — the engine leaves no orphans behind. */
export function deleteDatabase(id) {
  return api.delete(`/databases/${id}`);
}

/** Databases that exist on the server but the panel isn't managing. */
export function getUntracked(engine, { signal } = {}) {
  return api.get(`/databases/untracked?engine=${encodeURIComponent(engine)}`, {
    signal,
  });
}

/** Brings existing server databases under management. Never drops anything. */
export function adoptDatabases(engine, names) {
  return api.post("/databases/adopt", { engine, names });
}

/** The admin connection the panel uses for each engine. */
export function getConnections({ signal } = {}) {
  return api.get("/databases/connections", { signal });
}

/** Saves the connection; `test: true` makes the API also return `reachable`. */
export function updateConnection(engine, payload) {
  return api.put(`/databases/connections/${encodeURIComponent(engine)}`, {
    ...payload,
    test: true,
  });
}

/** Tests the STORED connection — not whatever is currently in the form. */
export function testConnection(engine) {
  return api.post(`/databases/connections/${encodeURIComponent(engine)}/test`);
}

/** Password is optional — omitted means the API generates a strong one. */
export function createDatabaseUser(databaseId, payload) {
  return api.post(`/databases/${databaseId}/users`, payload);
}

/** Runs ALTER USER on the engine, then updates the stored credential. */
export function updateUserPassword(databaseId, userId, password) {
  return api.put(`/databases/${databaseId}/users/${userId}/password`, {
    password,
  });
}

// Mongo drops and recreates the user, so a password is required there.
export function updateDatabaseUser(databaseId, userId, payload) {
  return api.patch(`/databases/${databaseId}/users/${userId}`, payload);
}

export function deleteDatabaseUser(databaseId, userId) {
  return api.delete(`/databases/${databaseId}/users/${userId}`);
}

// 202 with `status: "queued"` and no file yet: a dump can outlive nginx's timeout.
export function createExport(databaseId) {
  return api.post(`/databases/${databaseId}/export`);
}

/** Every export, newest first — in-flight rows included. */
export function getExports({ signal } = {}) {
  return api.get("/databases/exports", { signal });
}

/** Removes the row AND the file. Keyed by id: a queued row has no filename. */
export function deleteExport(id) {
  return api.delete(`/databases/exports/${id}`);
}

export function getEngineStatus(engine, { signal } = {}) {
  return api.get(`/databases/status/${encodeURIComponent(engine)}`, { signal });
}

/** 24h of query rate, connections and running threads. */
export function getDatabaseMetrics(engine, { signal } = {}) {
  return api.get(
    `/databases/metrics/history?engine=${encodeURIComponent(engine)}`,
    {
      signal,
    },
  );
}

export function getProcesses(engine, { signal } = {}) {
  return api.get(`/databases/processes?engine=${encodeURIComponent(engine)}`, {
    signal,
  });
}

/** KILL on SQL, killOp on Mongo. The engine keeps running; one query stops. */
export function killProcess(id, engine) {
  return api.delete(
    `/databases/processes/${encodeURIComponent(id)}?engine=${encodeURIComponent(engine)}`,
  );
}

export function getTables(databaseId, { signal } = {}) {
  return api.get(`/databases/${databaseId}/tables`, { signal });
}

// `redirect_url` holds a single-use token valid for 60 seconds: navigate
// immediately. Refusals are 422 with the reason.
export function phpmyadminSso(databaseId, databaseUserId, applicationId) {
  const params = {};
  if (databaseUserId) params.database_user_id = databaseUserId;
  // Which phpMyAdmin site to use; the token is written into that site's
  // directory. Omitted, the API picks the lowest id.
  if (applicationId) params.application_id = applicationId;

  return api.post(`/databases/${databaseId}/phpmyadmin-sso`, null, {
    params: Object.keys(params).length > 0 ? params : undefined,
  });
}
