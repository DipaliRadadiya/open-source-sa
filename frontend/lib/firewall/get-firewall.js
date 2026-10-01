import { read } from "@/lib/api/read";
import { serverFetch } from "@/lib/api/server-fetch";
import { listQuery } from "@/lib/schemas/list";
import {
  firewallResponseSchema,
  firewallPresetsResponseSchema,
  firewallRulesResponseSchema,
} from "@/lib/schemas/firewall";

// Only a real failure sets `failed` (an off firewall is a valid answer), so "couldn't
// ask" never renders as "unprotected".
export async function getFirewall() {
  const result = await read("/firewall", firewallResponseSchema);

  // Status and kind let the error box distinguish refused, crashed and unreachable.
  return { data: result.failed ? null : (result.data ?? null), failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}

// The status endpoint's full `rules` array feeds the safety checks; it must not drive
// this paginated list.
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

/** A failure falls back to raw port entry (the `custom` path), not a page failure. */
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
