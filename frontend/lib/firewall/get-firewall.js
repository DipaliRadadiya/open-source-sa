import { read } from "@/lib/api/read";
import { serverFetch } from "@/lib/api/server-fetch";
import { listQuery } from "@/lib/schemas/list";
import {
  firewallResponseSchema,
  firewallPresetsResponseSchema,
  firewallRulesResponseSchema,
} from "@/lib/schemas/firewall";

/**
 * GET /api/firewall — status, default policy and rules.
 *
 * Returns `{ data, failed }`. A firewall that is off is a legitimate answer and
 * gets said loudly; only a real failure sets `failed`, because "we couldn't ask"
 * must never render as "nothing is protecting this server".
 */
export async function getFirewall() {
  const result = await read("/firewall", firewallResponseSchema);

  // WHICH failure, not just that there was one: without the status and the
  // kind, the error box on this screen printed the same sentence whether the
  // API refused, crashed, or was not there at all.
  return { data: result.failed ? null : (result.data ?? null), failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}

/**
 * GET /api/firewall/rules — only the paginated table data.
 *
 * The status endpoint still supplies live UFW state and the complete rule set
 * used by the safety affordances, but its `rules` array must not drive the
 * list: a table page is not the whole rule set.
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
    // Carried so the rules card can say WHICH failure — a 403 is a permission
    // problem the reader can act on, a 500 is ours.
    status: result.status,
    failure: result.failure, message: result.message, debug: result.debug,
  };
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
