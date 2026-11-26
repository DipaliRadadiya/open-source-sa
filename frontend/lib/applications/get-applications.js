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
import { rememberedPerPage } from "@/lib/tables/remembered-per-page";
import { listQuery, EMPTY_LIST_META } from "@/lib/schemas/list";
import { applicationPhpResponseSchema } from "@/lib/schemas/php-settings";
import { applicationBansResponseSchema, applicationFail2banResponseSchema } from "@/lib/schemas/application-fail2ban";
import { applicationStagingResponseSchema, stagingSafetyCopiesSchema } from "@/lib/schemas/application-staging";
import { applicationUpdateResponseSchema } from "@/lib/schemas/application-update";

// A stale filter value is a 422, not an empty list.
export const getApplications = cache(async function getApplications(query = "") {
  const result = await read("/applications", applicationsResponseSchema, {
    searchParams: listQuery(query, { filters: { status: "status", site_type: "site_type" }, defaultPerPage: await rememberedPerPage() }),
  });

  return {
    applications: result.data?.applications ?? [],
    meta: result.data?.meta ?? EMPTY_LIST_META,
    failed: result.failed,
    status: result.status,
    failure: result.failure, message: result.message, debug: result.debug,
  };
});

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

export const getAiBotPolicies = cache(async function getAiBotPolicies() {
  const result = await read("/ai-bot-policies", aiBotPoliciesResponseSchema);
  return { policies: result.data?.ai_bot_policies ?? null, robotsTxt: result.data?.robots_txt ?? null, failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
});

export const getApplication = cache(async function getApplication(id) {
  const result = await read(`/applications/${id}`, applicationResponseSchema);
  return { application: result.data?.application ?? null, failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
});

export async function getApplicationIssues(id) {
  const { data, failed } = await read(
    `/applications/${id}/issues`,
    applicationIssuesResponseSchema,
  );
  return { issues: data?.issues ?? [], healthy: data?.healthy ?? true, failed };
}

export async function getApplicationWaf(id) {
  const result = await read(`/applications/${id}/waf`, applicationResponseSchema);
  return { application: result.data?.application ?? null, failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}

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

export async function getApplicationFail2ban(id) {
  const result = await read(`/applications/${id}/fail2ban`, applicationFail2banResponseSchema);
  return {
    config: result.data?.fail2ban ?? null,
    jailTemplate: result.data?.jail_template ?? "",
    filterTemplate: result.data?.filter_template ?? "",
    loginRuleOffered: result.data?.filter_scope === "wordpress_only",
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
}

export async function getApplicationBans(id) {
  const result = await read(`/applications/${id}/fail2ban/bans`, applicationBansResponseSchema);
  return { jail: result.data?.jail ?? null, banned: result.data?.banned ?? [], failed: result.failed, message: result.message };
}

export async function getApplicationPhp(id) {
  const result = await read(`/applications/${id}/php`, applicationPhpResponseSchema);
  return { php: result.data?.php ?? null, failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}

export async function getApplicationUpdate(id) {
  const result = await read(`/applications/${id}/update`, applicationUpdateResponseSchema);
  return { data: result.data ?? null, failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}

// Gated by app_log on the backend (it reads the access log).
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
    // null when unknown; [] would mean the server runs nothing.
    servingProfiles: capabilities?.capabilities?.serving_profiles ?? null,
    failed: result.failed,
    status: result.status,
    failure: result.failure, message: result.message, debug: result.debug,
  };
});

export async function getApplicationStaging(id) {
  const result = await read(`/applications/${id}/staging`, applicationStagingResponseSchema);
  return {
    staging: result.data?.staging ?? null,
    latestRun: result.data?.latest_run ?? null,
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
}

export async function getStagingSafetyCopies(id) {
  const result = await read(`/applications/${id}/staging/safety-copies`, stagingSafetyCopiesSchema);
  return { copies: result.data?.safety_copies ?? [], kept: result.data?.kept ?? null, failed: result.failed };
}

export const getPhpmyadminSite = cache(async function getPhpmyadminSite() {
  const result = await read("/applications", applicationsResponseSchema, {
    searchParams: { "filter[site_type]": "phpmyadmin", "filter[status]": "active", per_page: 100 },
  });

  if (result.failed) return { sites: null, site: null, known: false };

  const sites = result.data?.applications ?? [];
  return { sites, site: sites[0] ?? null, known: true };
});

export const getAdminerSite = cache(async function getAdminerSite() {
  const result = await read("/applications", applicationsResponseSchema, {
    searchParams: { "filter[site_type]": "adminer", "filter[status]": "active", per_page: 100 },
  });

  if (result.failed) return { sites: null, site: null, known: false };

  const sites = result.data?.applications ?? [];
  return { sites, site: sites[0] ?? null, known: true };
});
