/**
 * The settings fields every `PUT /api/fail2ban` has to carry.
 *
 * The endpoint rewrites fail2ban's config as a single unit, so it validates the
 * whole shape on every call — send only `jails` and it fails with "the bantime
 * field is required" before it touches anything. Any caller that changes one
 * part must therefore resend the parts it isn't changing.
 *
 * `ignoreIps` is passed separately because callers that add an address build
 * their own list; this only supplies it as the unchanged default.
 */
export function settingsPayload(settings, ignoreIps) {
  if (!settings) return {};
  return {
    bantime: settings.bantime,
    findtime: settings.findtime,
    maxretry: settings.maxretry,
    ignore_ips: ignoreIps ?? settings.ignore_ips ?? [],
  };
}
