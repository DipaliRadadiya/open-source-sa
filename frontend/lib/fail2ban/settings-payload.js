// The endpoint validates the whole config on every call, so unchanged parts are
// resent. `ignoreIps` overrides the stored list.
export function settingsPayload(settings, ignoreIps) {
  if (!settings) return {};
  return {
    bantime: settings.bantime,
    findtime: settings.findtime,
    maxretry: settings.maxretry,
    ignore_ips: ignoreIps ?? settings.ignore_ips ?? [],
  };
}
