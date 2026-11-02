// Stands in for lib/api/setup in component tests; answers live on globalThis.__setupApi.
const call =
  (name) =>
  (...args) => {
    const counts = globalThis.__setupApiCalls;
    if (counts) counts[name] = (counts[name] ?? 0) + 1;
    const answer = globalThis.__setupApi?.[name];
    return answer ? answer(...args) : Promise.reject(new Error(`no stub for ${name}`));
  };

export const fetchSetup = call("fetchSetup");
export const runSetupAction = call("runSetupAction");
