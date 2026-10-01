// Remove would 404 here; retrying install clears it. `path` marks files left on disk,
// where Remove stays offered.
export function failedWithNothingInstalled(version) {
  return version?.status === "failed" && !version?.path;
}
