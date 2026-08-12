import { api } from "@/lib/api/client";

/** Engine capability list — also the poll target while an install runs. */
export function getEngines({ signal } = {}) {
  return api.get("/databases/engines", { signal });
}

/**
 * Queued — apt takes minutes and holds a lock, so this returns 202 and the
 * caller polls `getEngines`. An engine already present returns 200 with
 * `queued: false`: a migrated server that already had MariaDB is a success,
 * not a conflict.
 */
export function installEngine(engine) {
  return api.post(`/databases/engines/${encodeURIComponent(engine)}`);
}

export function createDatabase(payload) {
  return api.post("/databases", payload);
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

/**
 * Saves the connection. `test: true` makes the API try it and return
 * `reachable`, so a save can report whether it actually worked rather than
 * only that it was written.
 */
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
  return api.put(`/databases/${databaseId}/users/${userId}/password`, { password });
}

/**
 * SQL renames with `RENAME USER` so grants survive; Mongo drops and recreates,
 * which is why a password is required there to change anything.
 */
export function updateDatabaseUser(databaseId, userId, payload) {
  return api.patch(`/databases/${databaseId}/users/${userId}`, payload);
}

export function deleteDatabaseUser(databaseId, userId) {
  return api.delete(`/databases/${databaseId}/users/${userId}`);
}

/**
 * Queued: `202` with the row already at `status: "queued"` and no file yet.
 * A dump of any real database outlives nginx's read timeout, so this used to
 * report failure while the dump quietly succeeded.
 */
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
  return api.get(`/databases/metrics/history?engine=${encodeURIComponent(engine)}`, {
    signal,
  });
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

/** Reclaims space left by deleted rows. No-op on Mongo. */
export function optimizeDatabase(databaseId) {
  return api.post(`/databases/${databaseId}/optimize`);
}

/** Rebuilds damaged tables. No-op on Mongo. */
export function repairDatabase(databaseId) {
  return api.post(`/databases/${databaseId}/repair`);
}

/**
 * A one-click login to phpMyAdmin for this database.
 *
 * Answers a `redirect_url` carrying a token good for 60 seconds, which the
 * shim on the phpMyAdmin site consumes once — so the browser has to be sent
 * there immediately rather than the link being stored or shown.
 *
 * MySQL and MariaDB only, and only when a phpMyAdmin site exists on this
 * server. Both refusals come back as 422 with the reason in the message.
 */
export function phpmyadminSso(databaseId, databaseUserId) {
  return api.post(`/databases/${databaseId}/phpmyadmin-sso`, null, {
    params: databaseUserId ? { database_user_id: databaseUserId } : undefined,
  });
}
