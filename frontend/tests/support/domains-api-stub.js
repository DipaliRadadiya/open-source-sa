// Component tests only: records domain writes; each test sets globalThis.__domainsApi.
export async function addDomain(...args) {
  return globalThis.__domainsApi.addDomain(...args);
}

export async function updateDomain(...args) {
  return globalThis.__domainsApi.updateDomain(...args);
}
