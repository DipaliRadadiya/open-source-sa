// A directory's listing size is the 4 KB entry, not its contents.
export function measuredSize(file, folderSizes) {
  return file.type === "dir" ? (folderSizes?.sizes?.[file.name] ?? null) : null;
}

export function sizeSortKey(file, folderSizes) {
  return file.type === "dir" ? (measuredSize(file, folderSizes)?.size ?? -1) : (file.size ?? 0);
}
