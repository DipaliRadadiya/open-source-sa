// Component tests only: no requests leave the process.
export async function readPhpSettings(...args) {
  return globalThis.__phpApi.readPhpSettings(...args);
}

export async function savePhpSettings(...args) {
  return globalThis.__phpApi.savePhpSettings(...args);
}
