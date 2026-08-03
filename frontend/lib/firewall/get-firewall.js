import { serverFetch } from "@/lib/api/server-fetch";
import {
  firewallResponseSchema,
  firewallPresetsResponseSchema,
} from "@/lib/schemas/firewall";

/**
 * GET /api/firewall — status, default policy and rules.
 *
 * Returns `{ data, failed }`. A firewall that is off is a legitimate answer and
 * gets said loudly; only a real failure sets `failed`, because "we couldn't ask"
 * must never render as "nothing is protecting this server".
 */
export async function getFirewall() {
  try {
    const res = await serverFetch("/firewall");
    if (!res.ok) return { data: null, failed: true };

    const parsed = firewallResponseSchema.safeParse(await res.json());
    return parsed.success ? { data: parsed.data, failed: false } : { data: null, failed: true };
  } catch {
    return { data: null, failed: true };
  }
}

/**
 * The preset shortcuts for the add-rule form.
 *
 * A missing preset list is not worth failing the page over — the form falls back
 * to raw port entry, which is the `custom` path anyway.
 */
export async function getFirewallPresets() {
  try {
    const res = await serverFetch("/firewall/presets");
    if (!res.ok) return [];

    const parsed = firewallPresetsResponseSchema.safeParse(await res.json());
    return parsed.success ? parsed.data.presets : [];
  } catch {
    return [];
  }
}
