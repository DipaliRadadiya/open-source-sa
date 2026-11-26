import { addIgnoreIp, getFail2ban } from "@/lib/api/fail2ban";

// Ignore list left out so a stale page can't drop an address someone else added.
export function settingsPayload(settings) {
  if (!settings) return {};
  return {
    bantime: settings.bantime,
    findtime: settings.findtime,
    maxretry: settings.maxretry,
  };
}

export async function ensureIgnored(ip) {
  try {
    await addIgnoreIp(ip);
  } catch (error) {
    if (error?.response?.status !== 422 || error.response.data?.errors) throw error;
    const { data } = await getFail2ban();
    if (!(data?.fail2ban?.settings?.ignore_ips ?? []).includes(ip)) throw error;
  }
}
