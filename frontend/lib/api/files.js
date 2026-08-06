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
