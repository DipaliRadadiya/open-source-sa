export const AUTO_FOLLOW_MAX_BYTES = 2 * 1024 * 1024;

export const FOLLOW_COOKIE = "sv_logs_follow";
export const LINES_COOKIE = "sv_logs_lines";

export function resolveFollow(preference, source) {
  if (preference === "off") return false;

  return Boolean(source?.readable) && (source?.size ?? 0) <= AUTO_FOLLOW_MAX_BYTES;
}
