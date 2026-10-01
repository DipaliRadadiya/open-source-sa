import { cache } from "react";
import { read } from "@/lib/api/read";
import {
  aiBotPoliciesResponseSchema,
  applicationIssuesResponseSchema,
  applicationResponseSchema,
  botTrafficResponseSchema,
  applicationsResponseSchema,
  serverCapabilitiesResponseSchema,
  siteTypesResponseSchema,
  wafOptionsResponseSchema,
} from "@/lib/schemas/application";
import { listQuery, EMPTY_LIST_META } from "@/lib/schemas/list";
import { applicationPhpResponseSchema } from "@/lib/schemas/php-settings";
import { applicationFail2banResponseSchema } from "@/lib/schemas/application-fail2ban";
import { applicationStagingResponseSchema } from "@/lib/schemas/application-staging";


// Search, filters and sort run in the API. A stale filter value is a 422, not an empty list.
export const getApplications = cache(async function getApplications(query = "") {
  const result = await read("/applications", applicationsResponseSchema, {
    searchParams: listQuery(query, { filters: { status: "status", site_type: "site_type" } }),
  });

  return {
    applications: result.data?.applications ?? [],
    meta: result.data?.meta ?? EMPTY_LIST_META,
    failed: result.failed,
    status: result.status,
    failure: result.failure, message: result.message, debug: result.debug,
  };
});

// Capped at the API maximum of 100. Argument-free so `cache` dedupes it.
export const getAllApplications = cache(async function getAllApplications() {
  const result = await read("/applications", applicationsResponseSchema, {
    searchParams: { per_page: 100 },
  });
  return { applications: result.data?.applications ?? [], failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
});

export const getSiteTypes = cache(async function getSiteTypes() {
  const result = await read("/site-types", siteTypesResponseSchema);
  return { siteTypes: result.data?.site_types ?? [], failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
});

// Server-wide catalog, cached per request.
export const getAiBotPolicies = cache(async function getAiBotPolicies() {
  const result = await read("/ai-bot-policies", aiBotPoliciesResponseSchema);
  return { policies: result.data?.ai_bot_policies ?? null, failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
});

// Cached per request: layout, page and `generateMetadata` all ask for it.
export const getApplication = cache(async function getApplication(id) {
  const result = await read(`/applications/${id}`, applicationResponseSchema);
  return { application: result.data?.application ?? null, failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
});

// Not cached: live checks. A failure returns nothing, so the page falls back to its own warnings.
export async function getApplicationIssues(id) {
  const { data, failed } = await read(
    `/applications/${id}/issues`,
    applicationIssuesResponseSchema,
  );
  return { issues: data?.issues ?? [], healthy: data?.healthy ?? true, failed };
}

// Same ApplicationResource but with `wafRules` loaded; not interchangeable
// with getApplication.
export async function getApplicationWaf(id) {
  const result = await read(`/applications/${id}/waf`, applicationResponseSchema);
  return { application: result.data?.application ?? null, failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}

// Server-wide, cached per request.
export const getWafOptions = cache(async function getWafOptions() {
  const result = await read("/waf-options", wafOptionsResponseSchema);
  return {
    categories: result.data?.waf_categories ?? [],
    modes: result.data?.waf_modes ?? [],
    failed: result.failed,
    status: result.status,
    failure: result.failure, message: result.message, debug: result.debug,
  };
});

/** One site's fail2ban config and bans. Not cached: bans change constantly. */
export async function getApplicationFail2ban(id) {
  const result = await read(`/applications/${id}/fail2ban`, applicationFail2banResponseSchema);
  return {
    // Null means "never set up".
    config: result.data?.fail2ban ?? null,
    jailTemplate: result.data?.jail_template ?? "",
    filterTemplate: result.data?.filter_template ?? "",
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
}

// Non-PHP site types get a 404, which is an answer, not a failure.
export async function getApplicationPhp(id) {
  const result = await read(`/applications/${id}/php`, applicationPhpResponseSchema);
  return { php: result.data?.php ?? null, failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}

// Gated by `app_log` on the backend, not `app_bot_blocker` — it reads the
// site's access log. Callers must check that permission before asking.
export async function getBotTraffic(id, days) {
  const result = await read(`/applications/${id}/bot-traffic?days=${days}`, botTrafficResponseSchema);
  return { traffic: result.data?.bot_traffic ?? null, failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}

export const getServerCapabilities = cache(async function getServerCapabilities() {
  const result = await read("/server/capabilities", serverCapabilitiesResponseSchema);
  const capabilities = result.data?.capabilities;
  return {
    webServer: capabilities?.web_server ?? null,
    serverIp: capabilities?.server_ip ?? null,
    temporaryDomainSuffixes: capabilities?.temporary_domain_suffixes ?? [],
    failed: result.failed,
    status: result.status,
    failure: result.failure, message: result.message, debug: result.debug,
  };
});

// A 404 means unsupported (WordPress-only), not a failure.
export async function getApplicationStaging(id) {
  const result = await read(`/applications/${id}/staging`, applicationStagingResponseSchema);
  return {
    staging: result.data?.staging ?? null,
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
}

// Active phpMyAdmin sites, matching the SSO endpoint's condition.
// A failed request returns `null`, NOT false. Argument-free so `cache` dedupes it.
export const getPhpmyadminSite = cache(async function getPhpmyadminSite() {
  const result = await read("/applications", applicationsResponseSchema, {
    searchParams: { "filter[site_type]": "phpmyadmin", "filter[status]": "active", per_page: 100 },
  });

  if (result.failed) return { sites: null, site: null, known: false };

  const sites = result.data?.applications ?? [];
  return { sites, site: sites[0] ?? null, known: true };
});
