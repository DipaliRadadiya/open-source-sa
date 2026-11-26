export function supportsRemoteUsers(result, engine) {
  const rows = Array.isArray(result) ? result : (result?.engines ?? []);
  const row = rows.find((candidate) => candidate?.engine === engine);
  return row?.supports_remote_users !== false;
}
