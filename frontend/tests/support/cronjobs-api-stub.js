// Component tests only: records cron job writes; each test sets globalThis.__cronApi.
export async function createCronjob(...args) {
  return globalThis.__cronApi.createCronjob(...args);
}

export async function updateCronjob(...args) {
  return globalThis.__cronApi.updateCronjob(...args);
}
