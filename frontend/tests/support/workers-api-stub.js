// Component tests only: records worker writes; each test sets globalThis.__workersApi.
export async function createWorker(...args) {
  return globalThis.__workersApi.createWorker(...args);
}

export async function updateWorker(...args) {
  return globalThis.__workersApi.updateWorker(...args);
}

export async function listWorkers() {
  return { data: { workers: [] } };
}
