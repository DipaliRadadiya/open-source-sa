/**
 * A failed install with nothing on disk, where Remove would always 404
 * (`destroy()` requires the version installed); retrying install clears it.
 * `path` marks a row found on disk: a half-failed install can leave files, and
 * Remove stays offered there.
 */
export function failedWithNothingInstalled(version) {
  return version?.status === "failed" && !version?.path;
}
