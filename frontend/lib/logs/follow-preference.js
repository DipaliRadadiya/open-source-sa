// Above this size auto-follow is off whatever the reader chose.
export const AUTO_FOLLOW_MAX_BYTES = 2 * 1024 * 1024;

export const FOLLOW_COOKIE = "sv_logs_follow";
// The line window, remembered the same way as on the application Logs page.
export const LINES_COOKIE = "sv_logs_lines";

// An explicit "off" is always honoured; "on" is still subject to the size limit.
export function resolveFollow(preference, source) {
  if (preference === "off") return false;

  return Boolean(source?.readable) && (source?.size ?? 0) <= AUTO_FOLLOW_MAX_BYTES;
}
