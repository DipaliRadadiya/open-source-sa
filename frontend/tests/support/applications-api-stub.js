// Stands in for lib/api/applications in component tests; records what was sent.
export const sent = [];

export async function createApplication(payload) {
  sent.push(payload);
  return { data: { application: { id: 1 } } };
}
export const checkApplicationPort = async () => ({ data: { available: true } });
export const getRepositories = async () => ({ data: { repositories: [] } });
export const getBranches = async () => ({ data: { branches: [] } });
