// Component tests only: records restores; each test sets globalThis.__restoreApi.
export async function restoreEnvironment(...args) {
  return globalThis.__restoreApi.restoreEnvironment(...args);
}

export async function restoreFileContent(...args) {
  return globalThis.__restoreApi.restoreFileContent(...args);
}
