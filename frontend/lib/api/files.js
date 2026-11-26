import { api } from "@/lib/api/client";
import { CHUNK_THRESHOLD_BYTES, MAX_CHUNK_BYTES, chunkSizeFor } from "@/lib/files/chunk-size";

export function listFiles(appId, path = "", { signal } = {}) {
  return api.get(`/applications/${appId}/files`, { params: { path }, signal });
}

export function searchFiles(appId, q, { path, signal } = {}) {
  return api.get(`/applications/${appId}/files/search`, { params: { q, path }, signal });
}

export function folderSizes(appId, path, { refresh = false, signal } = {}) {
  return api.get(`/applications/${appId}/files/sizes`, { params: { path, ...(refresh ? { refresh: 1 } : {}) }, signal });
}

export function getFileContent(appId, path) {
  return api.get(`/applications/${appId}/files/content`, { params: { path } });
}

export function saveFileContent(appId, path, content, version, { safetyCopy = true } = {}) {
  return api.put(`/applications/${appId}/files/content`, { path, content, safety_copy: safetyCopy, ...(version ? { version } : {}) });
}

export function restoreFileContent(appId, path, backup, { safetyCopy = true } = {}) {
  return api.post(`/applications/${appId}/files/content/restore`, { path, backup, safety_copy: safetyCopy });
}

export function fileDownloadUrl(appId, path) {
  const base = process.env.NEXT_PUBLIC_API_URL;
  return `${base}/api/applications/${appId}/files/download?path=${encodeURIComponent(path)}`;
}

// The download endpoint sends nosniff, which stops SVGs from rendering.
export function fileThumbnailUrl(appId, path) {
  const base = process.env.NEXT_PUBLIC_API_URL;
  return `${base}/api/applications/${appId}/files/preview?path=${encodeURIComponent(path)}`;
}

// The caller must URL.revokeObjectURL() the returned url.
export async function fetchFilePreview(appId, path, { signal } = {}) {
  const base = process.env.NEXT_PUBLIC_API_URL;
  const url = `${base}/api/applications/${appId}/files/preview?path=${encodeURIComponent(path)}`;

  const response = await fetch(url, {
    credentials: "include",
    headers: { Accept: "image/*, application/json" },
    signal,
  });

  if (response.ok) return { url: URL.createObjectURL(await response.blob()) };

  let message = null;
  try {
    const body = await response.json();
    if (typeof body?.message === "string" && body.message.trim()) message = body.message.trim();
  } catch {
    // not JSON
  }
  return { error: { status: response.status, message } };
}

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

export { CHUNK_THRESHOLD_BYTES, MAX_CHUNK_BYTES, chunkSizeFor };

const CHUNK_RETRIES = 3;

export async function uploadSpace(appId, { signal } = {}) {
  const { data } = await api.get(`/applications/${appId}/files/uploads/space`, { signal });
  return data;
}

// Chunks go one at a time so a single upload can't take over the PHP-FPM pool.
export async function uploadFileChunked(appId, path, file, { onProgress, signal } = {}) {
  const { data } = await api.post(
    `/applications/${appId}/files/uploads`,
    { path, size: file.size },
    { signal },
  );
  const uploadId = data.upload_id;
  const base = `/applications/${appId}/files/uploads/${uploadId}`;

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
          offset = res.data.received;
          break;
        } catch (error) {
          if (signal?.aborted || ++attempt > CHUNK_RETRIES) throw error;
          const { data: status } = await api.get(base, { signal });
          offset = status.received;
          if (offset >= end) break;
        }
      }

      onProgress?.(file.size ? offset / file.size : 1);
    }

    return await api.post(`${base}/finalize`, { path }, { signal });
  } catch (error) {
    // The server only cleans up abandoned part files after a day.
    api.delete(base).catch(() => {});
    throw error;
  }
}

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

export function deleteFile(appId, path, { permanent = false } = {}) {
  return api.delete(`/applications/${appId}/files`, {
    data: { path, confirm: true, ...(permanent ? { permanent: true } : null) },
  });
}

export const BULK_PATH_LIMIT = 250;

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

export function compressFiles(appId, paths, target) {
  return api.post(`/applications/${appId}/files/compress`, { paths, target });
}

export function setFilesPermissions(appId, paths, mode) {
  return api.put(`/applications/${appId}/files/permissions`, { paths, mode });
}

// count must match paths.length or the server refuses.
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

export function restoreTrashed(appId, batch, path) {
  return api.post(`/applications/${appId}/files/trash/restore`, { batch, path });
}

export function emptyTrash(appId, batch = null) {
  return api.delete(`/applications/${appId}/files/trash`, {
    data: { confirm: true, ...(batch ? { batch } : null) },
  });
}

export function fixApplicationPermissions(appId) {
  return api.post(`/applications/${appId}/fix-permissions`);
}

export function getArchiveJobs(appId, { signal } = {}) {
  return api.get(`/applications/${appId}/files/archive-jobs`, { signal });
}
