// Stands in for lib/api/docker in component tests; each test sets the answers.
// A call the test did not answer rejects, like a request that never got through.
const call =
  (name) =>
  (...args) => {
    const answer = globalThis.__dockerApi?.[name];
    return answer ? answer(...args) : Promise.reject(new Error(`no stub for ${name}`));
  };

export const searchDockerImages = call("search");
export const getDockerImageTags = call("tags");
export const inspectDockerImage = call("inspect");
export const updateContainerSettings = call("update");
export const pullContainerImage = call("pull");
export const getContainerSecrets = call("secrets");
