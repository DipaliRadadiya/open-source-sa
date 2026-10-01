import { api } from "@/lib/api/client";
import { CHUNK_THRESHOLD_BYTES, MAX_CHUNK_BYTES, chunkSizeFor } from "@/lib/files/chunk-size";

export function listFiles(appId, path = "", { signal } = {}) {
  return api.get(`/applications/${appId}/files`, { params: { path }, signal });
}

// Recursive; `path` optionally scopes it to a subtree (default: whole site).
export function searchFiles(appId, q, { path, signal } = {}) {
  return api.get(`/applications/${appId}/files/search`, { params: { q, path }, signal });
}

/** A folder's size, on demand: the backend walks the tree, so it is not in the listing. */
export function folderSize(appId, path) {
  return api.get(`/applications/${appId}/files/size`, { params: { path } });
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

// Used as a cookie-authenticated `<a href download>`; the endpoint always
// answers `application/octet-stream`.
export function fileDownloadUrl(appId, path) {
  const base = process.env.NEXT_PUBLIC_API_URL;
  return `${base}/api/applications/${appId}/files/download?path=${encodeURIComponent(path)}`;
}

// Not `download`: that is octet-stream + `nosniff` (SVG will not draw) and throttled at 20/min.
export function fileThumbnailUrl(appId, path) {
  const base = process.env.NEXT_PUBLIC_API_URL;
  return `${base}/api/applications/${appId}/files/preview?path=${encodeURIComponent(path)}`;
}

// Fetched rather than `<img src>` so a 422's reason can be shown and cross-domain APIs work.
// The CALLER OWNS the returned `url` and must `URL.revokeObjectURL` it.
export async function fetchFilePreview(appId, path, { signal } = {}) {
  const base = process.env.NEXT_PUBLIC_API_URL;
  const url = `${base}/api/applications/${appId}/files/preview?path=${encodeURIComponent(path)}`;

  const response = await fetch(url, {
    credentials: "include",
    headers: { Accept: "image/*, application/json" },
    signal,
  });

  if (response.ok) return { url: URL.createObjectURL(await response.blob()) };

  // Refusals are 422 with a translated `message`; other statuses fall back to
  // the caller's own copy.
  let message = null;
  try {
    const body = await response.json();
    if (typeof body?.message === "string" && body.message.trim()) message = body.message.trim();
  } catch {
    // not JSON — leave it null
  }
  return { error: { status: response.status, message } };
}

// `onProgress(fraction)` is optional.
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

// Imported then re-exported because this module also uses them locally.
export { CHUNK_THRESHOLD_BYTES, MAX_CHUNK_BYTES, chunkSizeFor };

const CHUNK_RETRIES = 3;

// Compare files against `usable` (safety floor subtracted); `available` is for display. Advisory only.
export async function uploadSpace(appId, { signal } = {}) {
  const { data } = await api.get(`/applications/${appId}/files/uploads/space`, { signal });
  return data;
}

// Sequential chunks so one upload cannot saturate the small FPM pool, and the part file's size is the resume offset.
// After any failure the server's received count is the authority.
export async function uploadFileChunked(appId, path, file, { onProgress, signal } = {}) {
  const { data } = await api.post(
    `/applications/${appId}/files/uploads`,
    // Declared up front so the server can reject an impossible upload early.
    { path, size: file.size },
    { signal },
  );
  const uploadId = data.upload_id;
  const base = `/applications/${appId}/files/uploads/${uploadId}`;

  // Chosen once so a resumed upload keeps the same boundaries, and clamped to
  // the server's `max_chunk` (its post_max_size, which rejects larger bodies).
  const chunkSize = Math.min(chunkSizeFor(file.size), data.max_chunk || Infinity);

  try {
    let offset = 0;

    while (offset < file.size) {
      const end = Math.min(offset + chunkSize, file.size);
      let attempt = 0;

      for (;;) {
        try {
          const res = await api.put(base, file.slice(offset, end), {
            signal,
            headers: { "Content-Type": "application/octet-stream" },
          });
          // Trust the server's total; catches a chunk that landed twice or partially.
          offset = res.data.received;
          break;
        } catch (error) {
          if (signal?.aborted || ++attempt > CHUNK_RETRIES) throw error;
          // Resync before retrying: a chunk that timed out may have been written.
          const { data: status } = await api.get(base, { signal });
          offset = status.received;
          if (offset >= end) break;
        }
      }

      onProgress?.(file.size ? offset / file.size : 1);
    }

    return await api.post(`${base}/finalize`, { path }, { signal });
  } catch (error) {
    // The server only reaps abandoned part files after a day; free the disk now.
    api.delete(base).catch(() => {});
    throw error;
  }
}

/** Small files use the single-request endpoint; larger ones are chunked. */
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

// `permanent` destroys instead of moving to the trash; only sent when explicitly chosen.
export function deleteFile(appId, path, { permanent = false } = {}) {
  return api.delete(`/applications/${appId}/files`, {
    data: { path, confirm: true, ...(permanent ? { permanent: true } : null) },
  });
}

/** The API 422s above this many paths per request; the UI enforces it too. */
export const BULK_PATH_LIMIT = 250;

/* Bulk write operations. The server answers with per-path `succeeded[]` /
 * `failed[]`; a missing path is a `failed` entry, not a 404. */

export function moveFiles(appId, paths, targetDirectory) {
  return api.put(`/applications/${appId}/files/rename`, {
    paths,
    target_directory: targetDirectory,
  });
}

export function copyFiles(appId, paths, targetDirectory) {
  return api.post(`/applications/${appId}/files/copy`, {
    paths,
    target_directory: targetDirectory,
  });
}

// Every source must sit in the same folder (`zip` runs from it); the caller checks.
export function compressFiles(appId, paths, target) {
  return api.post(`/applications/${appId}/files/compress`, { paths, target });
}

export function setFilesPermissions(appId, paths, mode) {
  return api.put(`/applications/${appId}/files/permissions`, { paths, mode });
}

// `count` must equal paths.length or the server refuses; it guards against a
// selection that changed underneath. Always send the real length.
export function deleteFiles(appId, paths, { permanent = false } = {}) {
  return api.delete(`/applications/${appId}/files`, {
    data: {
      paths,
      confirm: true,
      count: paths.length,
      ...(permanent ? { permanent: true } : null),
    },
  });
}

/* Trash. Restore and empty both answer with the refreshed list. */

// One path per call: there is no bulk restore endpoint (throttled 30/min).
export function restoreTrashed(appId, batch, path) {
  return api.post(`/applications/${appId}/files/trash/restore`, { batch, path });
}

// The whole trash, or one batch of it. The only unrecoverable action here.
export function emptyTrash(appId, batch = null) {
  return api.delete(`/applications/${appId}/files/trash`, {
    data: { confirm: true, ...(batch ? { batch } : null) },
  });
}

export function fixApplicationPermissions(appId) {
  return api.post(`/applications/${appId}/fix-permissions`);
}

// Includes recently finished jobs so a poll can tell finished from vanished.
export function getArchiveJobs(appId, { signal } = {}) {
  return api.get(`/applications/${appId}/files/archive-jobs`, { signal });
}
