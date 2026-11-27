import { api } from "@/lib/api/client";
import { RETRY_RESTART } from "@/lib/api/restart-blip";
import { chunkSizeFor } from "@/lib/files/chunk-size";

export function getEngines({ signal } = {}) {
  return api.get("/databases/engines", { signal });
}

export function installEngine(engine) {
  return api.post(`/databases/engines/${encodeURIComponent(engine)}`, undefined, RETRY_RESTART);
}

export function getDatabasesForApplication(applicationId, { signal } = {}) {
  return api.get("/databases", {
    params: { "filter[application_id]": applicationId, per_page: 100 },
    signal,
  });
}

export function createDatabase(payload) {
  return api.post("/databases", payload);
}

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

export function deleteDatabase(id) {
  return api.delete(`/databases/${id}`);
}

export function getUntracked(engine, { signal } = {}) {
  return api.get(`/databases/untracked?engine=${encodeURIComponent(engine)}`, {
    signal,
  });
}

export function adoptDatabases(engine, names) {
  return api.post("/databases/adopt", { engine, names });
}

export function getConnections({ signal } = {}) {
  return api.get("/databases/connections", { signal });
}

export function setRootPassword(engine, values) {
  return api.put(`/databases/connections/${encodeURIComponent(engine)}/root-password`, values);
}

export function updateConnection(engine, payload) {
  return api.put(`/databases/connections/${encodeURIComponent(engine)}`, {
    ...payload,
    test: true,
  });
}

export function closeRemoteAccess(engine) {
  return api.post(`/databases/connections/${encodeURIComponent(engine)}/remote-access/close`, {
    restart: true,
  });
}

export function testConnection(engine) {
  return api.post(`/databases/connections/${encodeURIComponent(engine)}/test`);
}

export function createDatabaseUser(databaseId, payload) {
  return api.post(`/databases/${databaseId}/users`, payload);
}

export function updateUserPassword(databaseId, userId, password) {
  return api.put(`/databases/${databaseId}/users/${userId}/password`, {
    password,
  });
}

export function updateDatabaseUser(databaseId, userId, payload) {
  return api.patch(`/databases/${databaseId}/users/${userId}`, payload);
}

export function deleteDatabaseUser(databaseId, userId) {
  return api.delete(`/databases/${databaseId}/users/${userId}`);
}

export function createExport(databaseId) {
  return api.post(`/databases/${databaseId}/export`);
}

export function getExports({ signal } = {}) {
  return api.get("/databases/exports", { signal });
}

export function deleteExport(id) {
  return api.delete(`/databases/exports/${id}`);
}

export function getImports(databaseId, { signal } = {}) {
  return api.get(`/databases/${databaseId}/imports`, { signal });
}

export function deleteImport(databaseId, importId) {
  return api.delete(`/databases/${databaseId}/imports/${importId}`);
}

const IMPORT_CHUNK_RETRIES = 3;

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
          const status = error?.response?.status;
          if (signal?.aborted || (status && status < 500) || ++attempt > IMPORT_CHUNK_RETRIES) throw error;
          offset = await received();
          if (offset >= end) break;
        }
      }
      onProgress?.(file.size ? offset / file.size : 1);
    }
  } catch (error) {
    // The server only cleans up abandoned uploads after a day.
    api.delete(base).catch(() => {});
    throw error;
  }

  return startImport(databaseId, importId, { safetyCopy });
}

export function startImport(databaseId, importId, { safetyCopy = true } = {}) {
  return api.post(`/databases/${databaseId}/imports/${importId}/start`, {
    confirm_replace: true,
    safety_copy: safetyCopy,
  });
}

export function getEngineStatus(engine, { signal } = {}) {
  return api.get(`/databases/status/${encodeURIComponent(engine)}`, { signal });
}

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

export function killProcess(id, engine) {
  return api.delete(
    `/databases/processes/${encodeURIComponent(id)}?engine=${encodeURIComponent(engine)}`,
  );
}

export function getTables(databaseId, { signal } = {}) {
  return api.get(`/databases/${databaseId}/tables`, { signal });
}

export function phpmyadminSso(databaseId, databaseUserId, applicationId) {
  const params = {};
  if (databaseUserId) params.database_user_id = databaseUserId;
  if (applicationId) params.application_id = applicationId;

  return api.post(`/databases/${databaseId}/phpmyadmin-sso`, null, {
    params: Object.keys(params).length > 0 ? params : undefined,
  });
}

export function adminerSso(databaseId, databaseUserId, applicationId) {
  const params = {};
  if (databaseUserId) params.database_user_id = databaseUserId;
  if (applicationId) params.application_id = applicationId;

  return api.post(`/databases/${databaseId}/adminer-sso`, null, {
    params: Object.keys(params).length > 0 ? params : undefined,
  });
}
