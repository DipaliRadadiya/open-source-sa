import { api } from "@/lib/api/client";
import { chunkSizeFor } from "@/lib/files/chunk-size";

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

/** Sets, changes or resets root's password (MySQL/MariaDB). The old one is not needed. */
export function setRootPassword(engine, values) {
  return api.put(`/databases/connections/${encodeURIComponent(engine)}/root-password`, values);
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

/** This database's latest 20 imports, newest first; polled while one runs. */
export function getImports(databaseId, { signal } = {}) {
  return api.get(`/databases/${databaseId}/imports`, { signal });
}

/** Cancels an upload or clears a finished row (409 while queued or running). */
export function deleteImport(databaseId, importId) {
  return api.delete(`/databases/${databaseId}/imports/${importId}`);
}

const IMPORT_CHUNK_RETRIES = 3;

// Begin, send the file in sequential raw chunks, then start. The user confirmed
// the replacement before this runs, so `confirm_replace` is sent with start.
// Chunks are clamped like the file manager's: the server's `max_chunk` is PHP's
// post_max_size, but nginx buffers less (lib/files/chunk-size.js).
export async function uploadAndStartImport(databaseId, file, { onProgress, onBegun, signal, safetyCopy = true } = {}) {
  const { data } = await api.post(
    `/databases/${databaseId}/imports`,
    { file_name: file.name, size_bytes: file.size },
    { signal },
  );
  const importId = data.import.id;
  onBegun?.(importId);
  const base = `/databases/${databaseId}/imports/${importId}`;
  const chunkSize = Math.min(chunkSizeFor(file.size), data.max_chunk || Infinity);

  // No per-import GET: the list row's `received_bytes` is the resume point.
  async function received() {
    const { data: list } = await getImports(databaseId, { signal });
    return list.imports.find((row) => row.id === importId)?.received_bytes ?? 0;
  }

  try {
    let offset = 0;
    while (offset < file.size) {
      const end = Math.min(offset + chunkSize, file.size);
      let attempt = 0;
      for (;;) {
        try {
          const res = await api.put(`${base}/chunk`, file.slice(offset, end), {
            signal,
            headers: { "Content-Type": "application/octet-stream" },
          });
          offset = res.data.received;
          break;
        } catch (error) {
          // A 4xx is an answer (too large, no longer uploading), not a dropped line.
          const status = error?.response?.status;
          if (signal?.aborted || (status && status < 500) || ++attempt > IMPORT_CHUNK_RETRIES) throw error;
          offset = await received();
          if (offset >= end) break;
        }
      }
      onProgress?.(file.size ? offset / file.size : 1);
    }

  } catch (error) {
    // The server reaps an abandoned upload only after a day; free the disk now.
    api.delete(base).catch(() => {});
    throw error;
  }

  // Not inside the cleanup above: a refused start (an export running) keeps the
  // uploaded file, and its row offers Start again.
  return startImport(databaseId, importId, { safetyCopy });
}

/**
 * Runs an uploaded import. `confirm_replace` is required by the API. `safetyCopy`
 * false skips the backup taken first, so a failed load has nothing to put back.
 */
export function startImport(databaseId, importId, { safetyCopy = true } = {}) {
  return api.post(`/databases/${databaseId}/imports/${importId}/start`, {
    confirm_replace: true,
    safety_copy: safetyCopy,
  });
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

// Adminer's sign-in: the same contract as phpMyAdmin's (single-use, 60s token).
// Opens MySQL, MariaDB and PostgreSQL; MongoDB is a 422.
export function adminerSso(databaseId, databaseUserId, applicationId) {
  const params = {};
  if (databaseUserId) params.database_user_id = databaseUserId;
  if (applicationId) params.application_id = applicationId;

  return api.post(`/databases/${databaseId}/adminer-sso`, null, {
    params: Object.keys(params).length > 0 ? params : undefined,
  });
}
