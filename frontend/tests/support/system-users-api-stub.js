// Stands in for lib/api/system-users in component tests; answers live on globalThis.__dockerApi
// next to the container-access ones, so one test sets the whole conversation.
const call =
  (name) =>
  (...args) => {
    const counts = globalThis.__dockerApiCalls;
    if (counts) counts[name] = (counts[name] ?? 0) + 1;
    const answer = globalThis.__dockerApi?.[name];
    return answer ? answer(...args) : Promise.reject(new Error(`no stub for ${name}`));
  };

export const findSystemUser = call("findUser");
export const setSystemUserSsh = call("setSsh");
