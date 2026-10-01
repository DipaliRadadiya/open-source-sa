// Takes `getEngines()`'s result as-is. Defaults to true for unknown engines or failed lookups:
// the server refuses with a clear message if unsupported.
export function supportsRemoteUsers(result, engine) {
  const rows = Array.isArray(result) ? result : (result?.engines ?? []);
  const row = rows.find((candidate) => candidate?.engine === engine);
  return row?.supports_remote_users !== false;
}
