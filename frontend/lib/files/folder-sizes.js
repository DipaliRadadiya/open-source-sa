// A folder's measured size from `GET …/files/sizes`, or null. The listing's `size`
// for a directory is the 4 KB directory entry itself, never its contents.
export function measuredSize(file, folderSizes) {
  return file.type === "dir" ? (folderSizes?.sizes?.[file.name] ?? null) : null;
}

// Sort key for the Size column: unmeasured folders are -1, so last when biggest first.
export function sizeSortKey(file, folderSizes) {
  return file.type === "dir" ? (measuredSize(file, folderSizes)?.size ?? -1) : (file.size ?? 0);
}
