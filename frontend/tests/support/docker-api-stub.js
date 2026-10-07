// Stands in for lib/api/docker in component tests; each test sets the answers.
const api = () => globalThis.__dockerApi ?? {};

export const searchDockerImages = (...args) => api().search(...args);
export const getDockerImageTags = (...args) => api().tags(...args);
export const inspectDockerImage = (...args) => api().inspect(...args);
