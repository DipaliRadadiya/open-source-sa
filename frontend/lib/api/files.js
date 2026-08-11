import { api } from "@/lib/api/client";

export function listFiles(appId, path = "", { signal } = {}) {
  return api.get(`/applications/${appId}/files`, { params: { path }, signal });
}

// Recursive, unlike the listing above — `path` optionally scopes it to a
// subtree, default is the whole site.
export function searchFiles(appId, q, { path, signal } = {}) {
  return api.get(`/applications/${appId}/files/search`, { params: { q, path }, signal });
}

export function getFileContent(appId, path) {
  return api.get(`/applications/${appId}/files/content`, { params: { path } });
}

export function saveFileContent(appId, path, content) {
  return api.put(`/applications/${appId}/files/content`, { path, content });
}

export function restoreFileContent(appId, path, backup) {
  return api.post(`/applications/${appId}/files/content/restore`, { path, backup });
}

// A plain, cookie-authenticated `<a href download>` — same pattern the
// database export download already uses — rather than a JS blob fetch. The
// endpoint always answers `application/octet-stream`, so the browser's normal
// download flow is exactly right here.
export function fileDownloadUrl(appId, path) {
  const base = process.env.NEXT_PUBLIC_API_URL;
  return `${base}/api/applications/${appId}/files/download?path=${encodeURIComponent(path)}`;
}

// `onProgress(fraction)` — undefined is fine, axios just skips the callback.
export function uploadFile(appId, path, file, { onProgress, signal } = {}) {
  const form = new FormData();
  form.append("path", path);
  form.append("file", file);
  return api.post(`/applications/${appId}/files/upload`, form, {
    signal,
    onUploadProgress: onProgress
      ? (e) => onProgress(e.total ? e.loaded / e.total : 0)
      : undefined,
  });
}

// Files at or under this go in one request; larger ones are chunked. The
// threshold sits below the panel pool's post_max_size (64M) with room to
// spare, so a single-shot upload can never be the thing that fails.
export const CHUNK_THRESHOLD_BYTES = 32 * 1024 * 1024;

// Must stay <= nginx's client_body_buffer_size (12M) on the panel vhost, so a
// chunk is buffered in RAM and never spilled to client_body_temp. That spill
// is a second disk write of every uploaded byte, and on a server that also
// hosts the customer sites it is their page cache that pays for it.
export const CHUNK_SIZE_BYTES = 8 * 1024 * 1024;

const CHUNK_RETRIES = 3;

/**
 * Resumable upload of a file of any size.
 *
 * Chunks go up sequentially, not in parallel: the panel's FPM pool is
 * `ondemand` with 10 workers, shared with every other panel request, and
 * saturating it with one user's upload would make the panel unresponsive
 * exactly while someone is watching it. Sequential also means the server can
 * treat the part file's size as the resume offset, with no per-chunk
 * bookkeeping.
 *
 * The server is the authority on how much it actually has — after any failure
 * we ask, rather than assuming our own count survived the interruption.
 */
export async function uploadFileChunked(appId, path, file, { onProgress, signal } = {}) {
  const { data } = await api.post(`/applications/${appId}/files/uploads`, { path }, { signal });
  const uploadId = data.upload_id;
  const base = `/applications/${appId}/files/uploads/${uploadId}`;

  try {
    let offset = 0;

    while (offset < file.size) {
      const end = Math.min(offset + CHUNK_SIZE_BYTES, file.size);
      let attempt = 0;

      for (;;) {
        try {
          const res = await api.put(base, file.slice(offset, end), {
            signal,
            headers: { "Content-Type": "application/octet-stream" },
          });
          // Trust the server's total over our own arithmetic: if a retried
          // chunk landed twice, or partially, this is what catches it.
          offset = res.data.received;
          break;
        } catch (error) {
          if (signal?.aborted || ++attempt > CHUNK_RETRIES) throw error;
          // Resync to what actually arrived before retrying — a chunk that
          // timed out client-side may well have been written in full.
          const { data: status } = await api.get(base, { signal });
          offset = status.received;
          if (offset >= end) break;
        }
      }

      onProgress?.(file.size ? offset / file.size : 1);
    }

    return await api.post(`${base}/finalize`, { path }, { signal });
  } catch (error) {
    // Abandoned part files are reaped server-side, but only after a day —
    // with no size limit that is a lot of disk to leave lying around on a
    // shared box when we know right now that it is dead.
    api.delete(base).catch(() => {});
    throw error;
  }
}

/**
 * Picks the cheaper path per file. Small files keep the single-request
 * endpoint: three extra round trips to move a 2 KB file is worse than the
 * thing chunking is there to avoid.
 */
export function uploadAnySize(appId, path, file, options = {}) {
  return file.size > CHUNK_THRESHOLD_BYTES
    ? uploadFileChunked(appId, path, file, options)
    : uploadFile(appId, path, file, options);
}

export function extractFile(appId, path, target) {
  return api.post(`/applications/${appId}/files/extract`, { path, target });
}

export function createDirectory(appId, path) {
  return api.post(`/applications/${appId}/files/directories`, { path });
}

export function renameFile(appId, path, target) {
  return api.put(`/applications/${appId}/files/rename`, { path, target });
}

export function copyFile(appId, path, target) {
  return api.post(`/applications/${appId}/files/copy`, { path, target });
}

export function compressFile(appId, path, target) {
  return api.post(`/applications/${appId}/files/compress`, { path, target });
}

export function setFilePermissions(appId, path, mode) {
  return api.put(`/applications/${appId}/files/permissions`, { path, mode });
}

export function deleteFile(appId, path) {
  return api.delete(`/applications/${appId}/files`, { data: { path, confirm: true } });
}

export function fixApplicationPermissions(appId) {
  return api.post(`/applications/${appId}/fix-permissions`);
}
