import { api } from "@/lib/api/client";
import { CHUNK_THRESHOLD_BYTES, MAX_CHUNK_BYTES, chunkSizeFor } from "@/lib/files/chunk-size";

export function listFiles(appId, path = "", { signal } = {}) {
  return api.get(`/applications/${appId}/files`, { params: { path }, signal });
}

// Recursive, unlike the listing above — `path` optionally scopes it to a
// subtree, default is the whole site.
export function searchFiles(appId, q, { path, signal } = {}) {
  return api.get(`/applications/${appId}/files/search`, { params: { q, path }, signal });
}

/**
 * How big a folder is, on demand.
 *
 * Not in the listing: the backend walks the tree to answer, so it is a
 * question you ask about one folder rather than a column the list fills in
 * for every row.
 */
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

// Imported rather than re-exported straight through: `export ... from` does
// not bind the names locally, and this module uses both below.
export { CHUNK_THRESHOLD_BYTES, MAX_CHUNK_BYTES, chunkSizeFor };

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
/**
 * What the server's disk can still take, in bytes.
 *
 * `usable` already has the safety floor subtracted, so it is the number to
 * compare a file against — `available` is only there to show the user how
 * much room actually exists. Advisory: the server re-checks on every write,
 * because other sites share this disk and are writing the whole time.
 */
export async function uploadSpace(appId, { signal } = {}) {
  const { data } = await api.get(`/applications/${appId}/files/uploads/space`, { signal });
  return data;
}

export async function uploadFileChunked(appId, path, file, { onProgress, signal } = {}) {
  const { data } = await api.post(
    `/applications/${appId}/files/uploads`,
    // Declared up front so the server can reject an impossible upload before
    // a single byte moves, rather than at whichever chunk fills the disk.
    { path, size: file.size },
    { signal },
  );
  const uploadId = data.upload_id;
  const base = `/applications/${appId}/files/uploads/${uploadId}`;

  // Chosen once, from the size declared to the server above — not per chunk,
  // so a resumed upload keeps the boundaries the first attempt used.
  //
  // Clamped to what the server said it will take. Only it knows its own
  // post_max_size, and a chunk over that is refused by middleware with "The
  // POST data is too large" before any upload code runs — a 6 GB file picking
  // 32 MB chunks against a stock 8M limit failed on the very first one.
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

// `permanent` destroys instead of moving to the trash. Omitting it means
// recoverable, which is what the API defaults to — the flag is only ever sent
// because someone deliberately ticked a box.
export function deleteFile(appId, path, { permanent = false } = {}) {
  return api.delete(`/applications/${appId}/files`, {
    data: { path, confirm: true, ...(permanent ? { permanent: true } : null) },
  });
}

/**
 * More than this in one request is a 422 — an argument vector has a kernel
 * limit, and crossing it would fail with some paths already handled. Enforced
 * in the UI so nobody assembles a selection they cannot act on.
 */
export const BULK_PATH_LIMIT = 250;

/* Bulk forms of the five write operations. They are NOT the single-path calls
 * in a loop: the server answers with per-path `succeeded[]` / `failed[]`, so a
 * batch that half-works reports as such instead of throwing on the first
 * missing file. A missing path is a `failed` entry here, where the single-path
 * form would 404. */

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

// Every source must sit in the same folder — `zip` runs from that folder so the
// archive holds bare names. The caller checks before offering the action.
export function compressFiles(appId, paths, target) {
  return api.post(`/applications/${appId}/files/compress`, { paths, target });
}

export function setFilesPermissions(appId, paths, mode) {
  return api.put(`/applications/${appId}/files/permissions`, { paths, mode });
}

// `count` must equal paths.length or the server refuses: `confirm` is true
// either way, so it cannot catch the realistic accident, which is acting on a
// selection that changed under you. Always send the real length.
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

/* The trash. Restore and empty both answer with the refreshed list, so neither
 * needs a follow-up GET. */

// One path per call: the API takes a single {batch, path} and has no bulk form,
// which is why there is no "restore everything" in the UI — twelve restores
// would be twelve requests against a 30/min throttle. See memory/backend-asks.md.
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
