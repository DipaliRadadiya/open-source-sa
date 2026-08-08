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
import { applicationPhpResponseSchema } from "@/lib/schemas/php-settings";
import { applicationFail2banResponseSchema } from "@/lib/schemas/application-fail2ban";
import { applicationStagingResponseSchema } from "@/lib/schemas/application-staging";


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

/**
 * One site's fail2ban: whether it is watching, and what it has caught.
 *
 * Read live on the server for every load — bans expire on their own and new
 * ones arrive without anyone asking, so a cached answer here is a wrong one.
 */
export async function getApplicationFail2ban(id) {
  const result = await read(`/applications/${id}/fail2ban`, applicationFail2banResponseSchema);
  return {
    // Null means "never set up", which the screen says differently from
    // "set up and switched off" — there is no such state any more.
    config: result.data?.fail2ban ?? null,
    jailTemplate: result.data?.jail_template ?? "",
    filterTemplate: result.data?.filter_template ?? "",
    failed: result.failed,
    status: result.status,
  };
}

/**
 * One site's PHP: version, limits, pool and the server's memory budget.
 *
 * Only for site types that serve PHP — the permission middleware answers 404
 * for the rest, which is a real answer rather than a failure.
 */
export async function getApplicationPhp(id) {
  const result = await read(`/applications/${id}/php`, applicationPhpResponseSchema);
  return { php: result.data?.php ?? null, failed: result.failed, status: result.status };
}

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

/**
 * One site's staging copy, if it has one.
 *
 * A 404 is an answer rather than a failure: staging is WordPress-only, so any
 * other site type is told it cannot have one instead of being shown an error
 * it can do nothing about.
 */
export async function getApplicationStaging(id) {
  const result = await read(`/applications/${id}/staging`, applicationStagingResponseSchema);
  return {
    staging: result.data?.staging ?? null,
    failed: result.failed,
    status: result.status,
  };
}
