import { cache } from "react";
import { read } from "@/lib/api/read";
import {
  aiBotPoliciesResponseSchema,
  applicationResponseSchema,
  botTrafficResponseSchema,
  applicationsResponseSchema,
  serverCapabilitiesResponseSchema,
  siteTypesResponseSchema,
  wafOptionsResponseSchema,
} from "@/lib/schemas/application";


export const getApplications = cache(async function getApplications() {
  const result = await read("/applications", applicationsResponseSchema);
  return { applications: result.data?.applications ?? [], failed: result.failed };
});

export const getSiteTypes = cache(async function getSiteTypes() {
  const result = await read("/site-types", siteTypesResponseSchema);
  return { siteTypes: result.data?.site_types ?? [], failed: result.failed };
});

// Server-wide catalog, identical for every application — cached per request so
// the bot list is fetched once even if something else on the page asks for it.
export const getAiBotPolicies = cache(async function getAiBotPolicies() {
  const result = await read("/ai-bot-policies", aiBotPoliciesResponseSchema);
  return { policies: result.data?.ai_bot_policies ?? null, failed: result.failed };
});

export async function getApplication(id) {
  const result = await read(`/applications/${id}`, applicationResponseSchema);
  return { application: result.data?.application ?? null, failed: result.failed, status: result.status };
}

// The firewall's own read. Same ApplicationResource, but with `wafRules`
// loaded — the exceptions and custom rules are absent from every other
// application endpoint, so this is not interchangeable with getApplication.
export async function getApplicationWaf(id) {
  const result = await read(`/applications/${id}/waf`, applicationResponseSchema);
  return { application: result.data?.application ?? null, failed: result.failed, status: result.status };
}

// Server-wide, identical for every application — cached per request.
export const getWafOptions = cache(async function getWafOptions() {
  const result = await read("/waf-options", wafOptionsResponseSchema);
  return {
    categories: result.data?.waf_categories ?? [],
    modes: result.data?.waf_modes ?? [],
    failed: result.failed,
  };
});

// Gated by `app_log` on the backend, not `app_bot_blocker` — it reads the
// site's access log. Callers must check that permission before asking.
export async function getBotTraffic(id, days) {
  const result = await read(`/applications/${id}/bot-traffic?days=${days}`, botTrafficResponseSchema);
  return { traffic: result.data?.bot_traffic ?? null, failed: result.failed };
}

export const getServerCapabilities = cache(async function getServerCapabilities() {
  const result = await read("/server/capabilities", serverCapabilitiesResponseSchema);
  return { webServer: result.data?.capabilities?.web_server ?? null, failed: result.failed };
});
