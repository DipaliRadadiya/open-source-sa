// A folder's measured size from `GET …/files/sizes`, or null. The listing's `size`
// for a directory is the 4 KB directory entry itself, never its contents.
export function measuredSize(file, folderSizes) {
  return file.type === "dir" ? (folderSizes?.sizes?.[file.name] ?? null) : null;
}

// Share of the folder on screen, for the bar; null without a complete total.
export function sizeShare(measured, folderSizes) {
  const total = folderSizes?.total?.size;
  if (!measured || !folderSizes?.complete || !total) return null;
  return Math.min(1, measured.size / total);
}

// Sort key for the Size column: unmeasured folders are -1, so last when biggest first.
export function sizeSortKey(file, folderSizes) {
  return file.type === "dir" ? (measuredSize(file, folderSizes)?.size ?? -1) : (file.size ?? 0);
}
