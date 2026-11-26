export function failedWithNothingInstalled(version) {
  return version?.status === "failed" && !version?.path;
}
