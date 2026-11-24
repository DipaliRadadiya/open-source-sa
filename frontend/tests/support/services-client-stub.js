// Stands in for lib/api/client in the services API tests; answers come from globalThis.__servicesApi.
export const api = {
  put: (...args) => globalThis.__servicesApi.put(...args),
  get: (...args) => globalThis.__servicesApi.get(...args),
  post: async () => ({ status: 200, data: {} }),
};
