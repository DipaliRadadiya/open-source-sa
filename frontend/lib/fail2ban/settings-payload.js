import { addIgnoreIp, getFail2ban } from "@/lib/api/fail2ban";

// The ban rules `PUT /fail2ban` requires on every call. The ignore list is left
// out on purpose: omitted, the server keeps its own, so a save from a stale page
// cannot drop an address someone else added since.
export function settingsPayload(settings) {
  if (!settings) return {};
  return {
    bantime: settings.bantime,
    findtime: settings.findtime,
    maxretry: settings.maxretry,
  };
}

// Puts `ip` on the ignore list unless the server's list already has it (a 422
// without field errors is "already there" or "full"; only the re-read tells which).
export async function ensureIgnored(ip) {
  try {
    await addIgnoreIp(ip);
  } catch (error) {
    if (error?.response?.status !== 422 || error.response.data?.errors) throw error;
    const { data } = await getFail2ban();
    if (!(data?.fail2ban?.settings?.ignore_ips ?? []).includes(ip)) throw error;
  }
}
