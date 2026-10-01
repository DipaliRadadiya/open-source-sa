/**
 * Whether this engine's accounts can be reached from outside the server.
 * Takes `getEngines()`'s result as-is (`{ engines, failed }`, or an array).
 *
 * Defaults to true for unknown engines, failed lookups or older APIs: the
 * server refuses with a clear message if unsupported.
 */
export function supportsRemoteUsers(result, engine) {
  const rows = Array.isArray(result) ? result : (result?.engines ?? []);
  const row = rows.find((candidate) => candidate?.engine === engine);
  return row?.supports_remote_users !== false;
}
