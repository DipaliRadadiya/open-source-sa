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
import { listQuery, EMPTY_LIST_META } from "@/lib/schemas/list";
import { applicationPhpResponseSchema } from "@/lib/schemas/php-settings";
import { applicationFail2banResponseSchema } from "@/lib/schemas/application-fail2ban";
import { applicationStagingResponseSchema } from "@/lib/schemas/application-staging";


/**
 * One page of the applications list.
 *
 * Search, both filters and the sort are the API's, not the browser's: it pages
 * at ten, so filtering the page we happen to hold would answer "which of these
 * ten" while the reader is asking "which of my sites".
 *
 * Both filters are validated server-side against the real sets, so a stale
 * value in the URL is a 422 rather than an empty list — which is the point.
 * "You have no applications" and "that filter matched nothing" look identical
 * on screen and mean completely different things.
 */
export const getApplications = cache(async function getApplications(query = "") {
  const result = await read("/applications", applicationsResponseSchema, {
    searchParams: listQuery(query, { filters: { status: "status", site_type: "site_type" } }),
  });

  return {
    applications: result.data?.applications ?? [],
    meta: result.data?.meta ?? EMPTY_LIST_META,
    failed: result.failed,
    status: result.status,
    failure: result.failure,
  };
});

/**
 * Every application, for the pickers — a backup filter's dropdown, the clone
 * target list — which need the whole set rather than a page of it.
 *
 * Capped at the API's own maximum of 100. A server with more sites than that
 * would silently lose the tail here, which is worth knowing about; the honest
 * fix at that point is a searchable combobox that queries the API, not a
 * bigger number.
 *
 * Takes no arguments so React's `cache` actually dedupes it — three of these
 * callers run on the same request.
 */
export const getAllApplications = cache(async function getAllApplications() {
  const result = await read("/applications", applicationsResponseSchema, {
    searchParams: { per_page: 100 },
  });
  return { applications: result.data?.applications ?? [], failed: result.failed, status: result.status, failure: result.failure };
});

export const getSiteTypes = cache(async function getSiteTypes() {
  const result = await read("/site-types", siteTypesResponseSchema);
  return { siteTypes: result.data?.site_types ?? [], failed: result.failed, status: result.status, failure: result.failure };
});

// Server-wide catalog, identical for every application — cached per request so
// the bot list is fetched once even if something else on the page asks for it.
export const getAiBotPolicies = cache(async function getAiBotPolicies() {
  const result = await read("/ai-bot-policies", aiBotPoliciesResponseSchema);
  return { policies: result.data?.ai_bot_policies ?? null, failed: result.failed, status: result.status, failure: result.failure };
});

// Cached per request: the layout needs the name for the breadcrumb, the page
// needs the record, and `generateMetadata` needs the title — three asks for one
// site that used to be three round-trips.
export const getApplication = cache(async function getApplication(id) {
  const result = await read(`/applications/${id}`, applicationResponseSchema);
  return { application: result.data?.application ?? null, failed: result.failed, status: result.status, failure: result.failure };
});

// The firewall's own read. Same ApplicationResource, but with `wafRules`
// loaded — the exceptions and custom rules are absent from every other
// application endpoint, so this is not interchangeable with getApplication.
export async function getApplicationWaf(id) {
  const result = await read(`/applications/${id}/waf`, applicationResponseSchema);
  return { application: result.data?.application ?? null, failed: result.failed, status: result.status, failure: result.failure };
}

// Server-wide, identical for every application — cached per request.
export const getWafOptions = cache(async function getWafOptions() {
  const result = await read("/waf-options", wafOptionsResponseSchema);
  return {
    categories: result.data?.waf_categories ?? [],
    modes: result.data?.waf_modes ?? [],
    failed: result.failed,
    status: result.status,
    failure: result.failure,
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
  return { php: result.data?.php ?? null, failed: result.failed, status: result.status, failure: result.failure };
}

// Gated by `app_log` on the backend, not `app_bot_blocker` — it reads the
// site's access log. Callers must check that permission before asking.
export async function getBotTraffic(id, days) {
  const result = await read(`/applications/${id}/bot-traffic?days=${days}`, botTrafficResponseSchema);
  return { traffic: result.data?.bot_traffic ?? null, failed: result.failed, status: result.status, failure: result.failure };
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
    failure: result.failure,
  };
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

/**
 * The active phpMyAdmin site, if this server has one.
 *
 * Asks the same question the SSO endpoint asks before it will issue a token:
 *
 *     Application::where('site_type', 'phpmyadmin')->where('status', Active)
 *
 * Without it the database pages cannot tell "open phpMyAdmin" from "there is
 * no phpMyAdmin to open", so the button was offered either way and you found
 * out by being refused. Matching the backend's own condition is what keeps the
 * two answers from drifting apart.
 *
 * A failed request returns `null`, NOT false: "we could not ask" and "there
 * isn't one" are different, and only the second should change what the button
 * says. Callers treat null as "carry on as before".
 *
 * `cache`d and argument-free so the list page and a detail page on the same
 * request share one call.
 */
export const getPhpmyadminSite = cache(async function getPhpmyadminSite() {
  const result = await read("/applications", applicationsResponseSchema, {
    searchParams: { "filter[site_type]": "phpmyadmin", "filter[status]": "active", per_page: 1 },
  });

  if (result.failed) return { site: null, known: false };

  return { site: result.data?.applications?.[0] ?? null, known: true };
});
