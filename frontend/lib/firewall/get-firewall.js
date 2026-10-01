import { read } from "@/lib/api/read";
import { serverFetch } from "@/lib/api/server-fetch";
import { listQuery } from "@/lib/schemas/list";
import {
  firewallResponseSchema,
  firewallPresetsResponseSchema,
  firewallRulesResponseSchema,
} from "@/lib/schemas/firewall";

/**
 * GET /api/firewall: status, default policy and rules.
 *
 * Returns `{ data, failed }`. A firewall that is off is a valid answer; only a
 * real failure sets `failed`, so "couldn't ask" never renders as "unprotected".
 */
export async function getFirewall() {
  const result = await read("/firewall", firewallResponseSchema);

  // Status and kind let the error box distinguish refused, crashed and unreachable.
  return { data: result.failed ? null : (result.data ?? null), failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}

/**
 * GET /api/firewall/rules: the paginated table data.
 *
 * The status endpoint still supplies live UFW state and the full rule set for the
 * safety checks, but its `rules` array must not drive the paginated list.
 */
export async function getFirewallRules(searchParams = {}) {
  const query = new URLSearchParams(searchParams).toString();
  const params = listQuery(query, {
    filters: { enabled: "enabled", action: "action", origin: "origin" },
  });

  const allowed = {
    enabled: ["0", "1"],
    action: ["allow", "deny"],
    origin: ["user", "default", "db_user"],
    sort: ["created_at", "-created_at", "port_from", "-port_from", "action", "-action", "protocol", "-protocol"],
  };

  for (const key of ["enabled", "action", "origin", "sort"]) {
    const value = searchParams[key];
    if (value && !allowed[key].includes(String(value))) {
      if (key === "sort") delete params.sort;
      else delete params[`filter[${key}]`];
    }
  }

  const result = await read("/firewall/rules", firewallRulesResponseSchema, { searchParams: params });
  return {
    rules: result.data?.rules ?? [],
    meta: result.data?.meta ?? { current_page: 1, per_page: 10, total: 0, last_page: 1 },
    failed: result.failed,
    // Lets the rules card name the cause: 403 is a permission issue, 500 is ours.
    status: result.status,
    failure: result.failure, message: result.message, debug: result.debug,
  };
}

/**
 * Preset shortcuts for the add-rule form. A failure is not worth failing the page
 * over; the form falls back to raw port entry (the `custom` path).
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
