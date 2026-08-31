// Auto-follow is off above this, whatever the reader last chose: the limit
// exists to stop a tail running against a file big enough to hurt.
export const AUTO_FOLLOW_MAX_BYTES = 2 * 1024 * 1024;

export const FOLLOW_COOKIE = "sv_logs_follow";

/**
 * Whether live tailing starts on.
 *
 * The reader's choice used to last exactly as long as the page did — turning
 * it off and refreshing turned it straight back on, because nothing was
 * remembered and the default was simply re-applied.
 *
 * An explicit "off" is honoured everywhere. An explicit "on" is still subject
 * to the size limit: saying yes to a 4 KB nginx log is not consent to tail a
 * 10 MB syslog, and the guard is the reason that distinction exists.
 */
export function resolveFollow(preference, source) {
  if (preference === "off") return false;

  return Boolean(source?.readable) && (source?.size ?? 0) <= AUTO_FOLLOW_MAX_BYTES;
}
