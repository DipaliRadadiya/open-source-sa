import { z } from "zod";

/**
 * One site's fail2ban.
 *
 * A different feature from the server-level one: these jails watch this site's
 * own access log, not the system's auth logs. The payload is its own shape —
 * `{fail2ban_enabled, jails}` — not an ApplicationResource.
 */
const appJailStatsSchema = z.object({
  currently_failed: z.number().nullable().optional(),
  total_failed: z.number().nullable().optional(),
  currently_banned: z.number().nullable().optional(),
  total_banned: z.number().nullable().optional(),
});

const applicationJailSchema = z.object({
  // `app-{id}-generic` / `app-{id}-wplogin` — an internal identifier that must
  // never reach the screen. `jailKind()` turns it into something readable.
  jail: z.string(),
  enabled: z.boolean().default(false),
  banned: z.array(z.string()).default([]),
  // Null when the jail is not active: nothing is being counted, which is not
  // the same as counted zero.
  stats: appJailStatsSchema.nullable().optional(),
});

export const applicationFail2banResponseSchema = z.object({
  fail2ban_enabled: z.boolean().default(false),
  jails: z.array(applicationJailSchema).default([]),
});

/**
 * The last segment of `app-{id}-{kind}`, which is the only part that means
 * anything to a person.
 *
 * Anything the backend adds later falls back to the raw name rather than
 * rendering an empty label — the same contract the bot blocker's group labels
 * use.
 */
export function jailKind(jail) {
  const match = String(jail ?? "").match(/^app-\d+-(.+)$/);
  return match ? match[1] : String(jail ?? "");
}

/** Every address banned in any of this site's jails, once each. */
export function bannedAddresses(jails = []) {
  return [...new Set(jails.flatMap((jail) => jail.banned ?? []))];
}

/**
 * The totals across this site's jails.
 *
 * Summed rather than shown per jail: two jails with four counters each is
 * eight numbers and no answer. Null stats (an inactive jail) contribute
 * nothing rather than counting as zero.
 */
export function jailTotals(jails = []) {
  return jails.reduce(
    (totals, jail) => ({
      currentlyFailed: totals.currentlyFailed + (jail.stats?.currently_failed ?? 0),
      totalFailed: totals.totalFailed + (jail.stats?.total_failed ?? 0),
      currentlyBanned: totals.currentlyBanned + (jail.stats?.currently_banned ?? 0),
      totalBanned: totals.totalBanned + (jail.stats?.total_banned ?? 0),
    }),
    { currentlyFailed: 0, totalFailed: 0, currentlyBanned: 0, totalBanned: 0 },
  );
}

/**
 * Mirrors Laravel's `ip` rule closely enough to answer in the field.
 *
 * The backend validates with `filter_var(..., FILTER_VALIDATE_IP)`, which
 * accepts both families; this refuses the shapes people actually mistype (a
 * CIDR range, a hostname, a port) rather than trying to be a full parser.
 */
const IPV4 = /^(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}$/;

/**
 * IPv6, checked in steps rather than as one expression.
 *
 * A single regex for this is either wrong or unreadable, and `net.isIP()` is
 * not available in the browser. The rules: hex groups of up to four digits
 * separated by colons, at most one `::`, and no more than eight groups (seven
 * when `::` already stands for at least one).
 */
function isIpv6(value) {
  if (!value.includes(":")) return false;
  if (!/^[0-9a-f:]+$/i.test(value)) return false;

  const shorthands = value.split("::").length - 1;
  if (shorthands > 1) return false;

  const groups = value.split(":").filter((group) => group !== "");
  if (groups.some((group) => group.length > 4)) return false;

  return shorthands === 1 ? groups.length <= 7 : groups.length === 8;
}

export function isIpAddress(value) {
  const trimmed = String(value ?? "").trim();
  return IPV4.test(trimmed) || isIpv6(trimmed);
}

