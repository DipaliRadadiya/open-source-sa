import { z } from "zod";
import { listMetaSchema } from "./list.js";
import { passwordField, usernameField } from "./system-user.js";

const textField = z.object({
  name: z.string(),
  label: z.string(),
  type: z.string().default("text"),
  required: z.boolean().default(false),
  advanced: z.boolean().default(false),
  default: z.unknown().optional(),
  help: z.string().nullish(),
  placeholder: z.string().nullish(),
  options: z.array(z.object({ value: z.string(), label: z.string() })).default([]),
  source: z.string().nullish(),
  depends_on: z.string().nullish(),
  generate: z.boolean().default(false),
  // package_manager → the install+build command it fills in. PHP sends an
  // empty map as [].
  build_templates: z
    .preprocess((v) => (Array.isArray(v) ? {} : v), z.record(z.string(), z.string()))
    .optional(),
});

// Either end may be absent, meaning unbounded in that direction.
const versionRange = z
  .object({
    min: z.string().nullish(),
    max: z.string().nullish(),
  })
  .nullish();

export const siteTypeSchema = z.object({
  name: z.string(),
  title: z.string(),
  tagline: z.string().nullish(),
  icon: z.string().nullish(),
  category: z.string().nullish(),
  popular: z.boolean().default(false),
  method: z.string().nullish(),
  serving_profile: z.string().nullish(),
  needs_database: z.boolean().default(false),
  available: z.boolean().default(true),
  unavailable_reason: z.string().nullish(),
  // Branch on this, not on `unavailable_reason` (display text):
  // 'runtime' | 'database' | 'web_server', null when available.
  unavailable_code: z.string().nullish(),
  // Engines this type installs on (e.g. ["mysql", "mariadb"]); read by
  // `acceptedEngines` in lib/applications/database-readiness.js. `[]` is a real
  // answer: a type with no installer has no list.
  accepted_engines: z.array(z.string()).nullish(),
  installable_runtime: z.string().nullish(),
  has_installer: z.boolean().default(false),
  // Supported runtime versions, both ends inclusive and either end nullable.
  php_version_range: versionRange,
  node_version_range: versionRange,
  fields: z.array(textField).default([]),
});

export const siteTypesResponseSchema = z.object({
  site_types: z.array(siteTypeSchema).default([]),
});

export const systemUserOptionSchema = z.object({
  id: z.number(),
  username: z.string(),
}).passthrough();

export const systemUsersResponseSchema = z.object({
  system_users: z.array(systemUserOptionSchema).default([]),
  // `ssh_access_enforced`: whether the SSH switch keeps anyone out yet. Null
  // when sshd could not be asked, which is not the same as "no".
  meta: listMetaSchema.extend({ ssh_access_enforced: z.boolean().nullable().optional() }),
});

// Read live from systemd on every request; absent unless `has_process`.
const processSchema = z.object({
  state: z.string().nullish(),
  sub_state: z.string().nullish(),
  since: z.string().nullish(),
  memory: z.union([z.number(), z.string()]).nullish(),
  restarts: z.number().nullish(),
}).passthrough();

const webhookSchema = z.object({
  enabled: z.boolean().default(false),
  provider: z.string().nullish(),
  url: z.string().nullish(),
  secret: z.string().nullish(),
  verification: z.string().nullish(),
  // True when the panel added the webhook to the repository itself; false
  // means the URL and secret have to be pasted in by hand.
  registered: z.boolean().default(false),
  last_delivered_at: z.string().nullish(),
  last_delivered_at_human: z.string().nullish(),
}).passthrough();

/**
 * One thing the server thinks is wrong with a site.
 *
 * `message` arrives translated and is shown as sent: it carries numbers this
 * side does not have, and re-wording it would make screens disagree.
 */
export const applicationIssueSchema = z.object({
  type: z.string(),
  severity: z.enum(["warning", "critical"]).catch("warning"),
  message: z.string(),
  meta: z.record(z.string(), z.unknown()).default({}),
});

export const applicationIssuesResponseSchema = z.object({
  issues: z.array(applicationIssueSchema).default([]),
  healthy: z.boolean().default(true),
});

export const applicationSchema = z.object({
  id: z.number(),
  name: z.string(),
  // Nullable since domains moved to their own table; required here would reject
  // the whole list.
  domain: z.string().nullish(),
  // The address to open, decided by the server: `http://` until the site has a
  // servable certificate. Do not assemble `https://{domain}` instead.
  url: z.string().nullish(),
  site_type: z.string(),
  site_type_title: z.string().nullish(),
  /*
   * Last site-type detection result. All nullish: nothing is populated until
   * Detect is pressed (probing on create would cache "nothing found").
   * `matched` is the file the verdict rests on; `suggested` is pre-validated
   * against the apply endpoint, so accepting it cannot return a 422.
   */
  site_type_detection: z
    .object({
      detected: z.string().nullish(),
      detected_title: z.string().nullish(),
      confidence: z.number().nullish(),
      matched: z.string().nullish(),
      checked_at: z.string().nullish(),
      suggested: z.string().nullish(),
    })
    .nullish(),
  serving_profile: z.string().nullish(),
  rendering_type: z.string().nullish(),
  status: z.enum(["pending", "provisioning", "active", "failed"]).catch("pending"),
  status_title: z.string().nullish(),
  deployed: z.boolean().default(false),
  system_user: systemUserOptionSchema.nullish(),
  php_version: z.string().nullish(),
  node_version: z.string().nullish(),
  app_port: z.number().nullish(),
  web_root: z.string().nullish(),
  // The directory the web server serves. The deploy script's `{path}` token
  // expands to this (GitDeployer's `expand()`), not to `path`.
  document_root: z.string().nullish(),
  // Where the site's code lives: the document root, or its parent for types
  // with a fixed web root (Laravel's `public`). Cron commands resolve from it.
  path: z.string().nullish(),
  build_command: z.string().nullish(),
  start_command: z.string().nullish(),
  git_account_id: z.number().nullish(),
  repository: z.string().nullish(),
  repository_url: z.string().nullish(),
  branch: z.string().nullish(),
  // PHP serializes an empty associative array as `[]`; coerce it back to an
  // object or the whole list fails to parse.
  settings: z.preprocess(
    (value) => (Array.isArray(value) ? {} : value),
    z.record(z.string(), z.unknown()).default({}),
  ),
  // Must be declared: this object does not passthrough, so Zod strips them.
  has_process: z.boolean().default(false),
  process: processSchema.nullish(),
  webhook: webhookSchema.nullish(),
  basic_auth_enabled: z.boolean().default(false),
  basic_auth_username: z.string().nullish(),
  // False when the app's own client uses the Authorization header, which Basic
  // Auth would consume. Defaults true for backends that predate the flag.
  basic_auth_supported: z.boolean().default(true),
  // OpenLiteSpeed has no equivalent of the nginx WAF rule set.
  waf_supported: z.boolean().default(true),
  // When the CURRENT provisioning run started; `created_at` is wrong after a retry.
  provisioning_started_at: z.string().nullish(),
  provisioning_started_at_human: z.string().nullish(),
  is_disabled: z.boolean().default(false),
  disabled_at: z.string().nullish(),
  // "This site has a jail configured", not "fail2ban is protecting this site".
  // Whether the fail2ban service is up is server-wide (`GET /services`); live
  // jail state has its own endpoint.
  fail2ban_enabled: z.boolean().default(false),
  ai_bot_policy: z.string().nullish(),
  ai_bot_policy_title: z.string().nullish(),
  // Per-bot overrides on top of the policy. `whenLoaded('botRules')` on the
  // backend, so currently only returned by the bot-blocker PUT.
  bot_blocked: z.array(z.string()).default([]),
  bot_allowed: z.array(z.string()).default([]),
  // npm | yarn | pnpm | bun, for the ssr/csr rendering types.
  package_manager: z.string().nullish(),
  is_staging: z.boolean().default(false),
  production_application_id: z.number().nullish(),
  has_staging: z.boolean().default(false),
  cloned_from_application_id: z.number().nullish(),
  // `waf_exceptions`/`waf_custom_rules` are `whenLoaded`: only returned by
  // GET /applications/{id}/waf. Deliberately NOT defaulted to []: absent means
  // "not loaded", not "no exceptions".
  waf_enabled: z.boolean().default(false),
  waf_mode: z.string().nullish(),
  waf_mode_title: z.string().nullish(),
  waf_categories: z.array(z.string()).default([]),
  waf_exceptions: z.array(z.string()).optional(),
  waf_custom_rules: z.array(z.string()).optional(),
  last_commit: z.union([z.string(), z.record(z.string(), z.unknown())]).nullish(),
  // What is actually on disk. Deploys are in place, so a deploy that fails after
  // checkout leaves the new commit live while `last_commit` (written on success
  // only) names the old one. Sent on a single application, not in the list.
  code_on_disk: z
    .object({
      commit: z.string().nullish(),
      state: z.enum(["deployed", "incomplete", "deploying"]).nullable().catch(null),
      message: z.string().nullish(),
    })
    .nullish(),
  last_deployed_at: z.string().nullish(),
  last_deployed_at_human: z.string().nullish(),
  steps: z.array(z.string()).default([]),
  failed_step: z.string().nullish(),
  // True when the git account was deleted (FK is nullOnDelete): the site keeps
  // its repository and branch but has no credential, so the next deploy fails.
  git_account_missing: z.boolean().nullish(),
  failed_reason: z.string().nullish(),
  // Set only when the cause is identified (usually null), already localized;
  // fall back to the step otherwise.
  failed_reason_title: z.string().nullish(),
  reference: z.string().nullish(),
  // Shown in the sites list; must be declared or Zod strips them.
  directory_size_bytes: z.number().nullish(),
  directory_size_measured_at: z.string().nullish(),
  directory_size_measured_at_human: z.string().nullish(),
  created_at: z.string().nullish(),
  created_at_human: z.string().nullish(),
});

export const applicationsResponseSchema = z.object({
  applications: z.array(applicationSchema).default([]),
  meta: listMetaSchema,
});

export const applicationResponseSchema = z.object({
  application: applicationSchema,
});

export const portCheckResponseSchema = z.object({
  port_check: z.object({
    available: z.boolean(),
    reason: z.string().nullish(),
    service: z.string().nullish(),
    suggested_port: z.number().nullish(),
    message: z.string().nullish(),
  }),
});

// Catalog behind the AI Bot Blocker screen. `config/ai_bots.php` feeds both this
// endpoint and the vhost, so render from the response rather than hardcoding.
export const aiBotPolicySchema = z.object({
  title: z.string(),
  description: z.string(),
  blocked_bots: z.array(z.string()).default([]),
  blocked_count: z.number().default(0),
});

export const aiBotPoliciesResponseSchema = z.object({
  ai_bot_policies: z.record(z.string(), aiBotPolicySchema).default({}),
});

// Which AI bots hit this site, from its access log. `unavailable` (log could not
// be read) is NOT `empty` (read, nothing found); never show it as "no bots".
export const botTrafficBotSchema = z.object({
  bot: z.string(),
  hits: z.number().default(0),
  // training | search | agent | custom
  category: z.string().nullish(),
  // What the CURRENT settings do to it — policy plus any per-bot rules.
  blocked: z.boolean().default(false),
  last_seen: z.string().nullish(),
  last_seen_human: z.string().nullish(),
});

export const botTrafficResponseSchema = z.object({
  bot_traffic: z.object({
    status: z.string().default("unavailable"),
    days: z.number().default(7),
    scanned_lines: z.number().default(0),
    since: z.string().nullish(),
    bots: z.array(botTrafficBotSchema).default([]),
    totals: z
      .object({
        bots: z.number().default(0),
        hits: z.number().default(0),
        blocked_hits: z.number().default(0),
      })
      .default({ bots: 0, hits: 0, blocked_hits: 0 }),
  }),
});

// Rule categories and modes for the Firewall screen's labels. Titles only: the
// backend has no per-category description, so hints live in the message files.
export const wafOptionSchema = z.object({
  value: z.string(),
  title: z.string(),
});

export const wafOptionsResponseSchema = z.object({
  waf_categories: z.array(wafOptionSchema).default([]),
  waf_modes: z.array(wafOptionSchema).default([]),
});

// `web_server` gates the firewall screen: the 8G ruleset has no OpenLiteSpeed
// implementation. `server_ip` and `temporary_domain_suffixes` are the server's
// answer to "what address do sites point at, and which wildcard-DNS host resolves it".
export const serverCapabilitiesResponseSchema = z.object({
  capabilities: z.object({
    stack: z.string().nullish(),
    web_server: z.string().nullish(),
    server_ip: z.string().nullish(),
    temporary_domain_suffixes: z.array(z.string()).default([]),
  }),
});

// The API takes username+password together whenever `enabled` is true (no
// password-only call), so both are required only in that branch.
export const securityFormSchema = z
  .object({
    enabled: z.boolean(),
    username: z.string(),
    password: z.string(),
  })
  .superRefine((data, ctx) => {
    if (!data.enabled) return;
    // Mirrors UpdateBasicAuthRequest (`regex:/^[^:\s]+$/`, `max:255`).
    const username = data.username.trim();
    if (!username) {
      ctx.addIssue({ path: ["username"], code: "custom", message: "required_username" });
    } else if (username.includes(":")) {
      ctx.addIssue({ path: ["username"], code: "custom", message: "securityUsernameColon" });
    } else if (/\s/.test(username)) {
      ctx.addIssue({ path: ["username"], code: "custom", message: "securityUsernameSpaces" });
    } else if (username.length > 255) {
      ctx.addIssue({ path: ["username"], code: "custom", message: "max255" });
    } else if (!/^[A-Za-z0-9._@-]+$/.test(username)) {
      // Browsers send a non-ASCII name in different encodings (UTF-8 or
      // Latin-1), so ünïcode could be saved and then never sign in.
      ctx.addIssue({ path: ["username"], code: "custom", message: "securityUsernameAscii" });
    }
    if (!data.password) {
      ctx.addIssue({ path: ["password"], code: "custom", message: "required_password" });
    } else if (data.password.length < 8) {
      ctx.addIssue({ path: ["password"], code: "custom", message: "min8" });
    } else if (data.password.length > 255) {
      ctx.addIssue({ path: ["password"], code: "custom", message: "max255" });
    }
  });

const applicationDomainLabel =
  /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/;

/** A hostname only — no protocol, path, query, credentials, or port. */
export function isValidApplicationDomain(value) {
  const domain = String(value ?? "").trim().toLowerCase();
  if (
    !domain ||
    domain.length > 253 ||
    domain.endsWith(".") ||
    domain.includes("://") ||
    /[/?#:@]/.test(domain)
  )
    return false;

  const labels = domain.split(".");
  // An all-digit last label is an IP address, never a name.
  if (/^\d+$/.test(labels[labels.length - 1])) return false;
  return labels.length >= 2 && labels.every((label) => applicationDomainLabel.test(label));
}

/**
 * Turn a pasted URL into a hostname to offer back to the user. Never mutates
 * input silently: the UI renders an explicit "Use …" action.
 */
export function suggestApplicationDomain(value) {
  const entered = String(value ?? "").trim();
  if (!entered) return null;

  try {
    const parsed = new URL(
      entered.includes("://") ? entered : `http://${entered}`,
    );
    const candidate = parsed.hostname.toLowerCase().replace(/\.$/, "");
    return candidate !== entered && isValidApplicationDomain(candidate)
      ? candidate
      : null;
  } catch {
    return null;
  }
}

export const createApplicationSchema = z.object({
  site_type: z.string().min(1, "applicationTypeRequired"),
  // 240 matches StoreApplicationRequest.
  name: z.string().trim().min(1, "applicationNameRequired").max(240, "max240"),
  domain: z
    .string()
    .trim()
    .min(1, "applicationDomainRequired")
    .max(255, "tooLong")
    .refine(isValidApplicationDomain, "hostnameInvalid"),
  // Turned off for users who cannot create system users (the API refuses).
  generate_system_user: z.boolean().default(true),
  // Required only when picking an existing user. `superRefine` so the message
  // lands on `system_user_id`, where the control is.
  system_user_id: z.union([z.coerce.number().int().positive(), z.literal("")]).optional(),
  // The new user's details, only read while generating one.
  system_user_username: z.string().optional(),
  system_user_password: z.string().optional(),
}).passthrough().superRefine((values, ctx) => {
  if (!values.generate_system_user && !values.system_user_id) {
    ctx.addIssue({
      code: z.ZodIssueCode.custom,
      path: ["system_user_id"],
      message: "applicationSystemUserRequired",
    });
  }
  if (!values.generate_system_user) return;
  const checks = [
    ["system_user_username", usernameField, values.system_user_username ?? ""],
    ["system_user_password", passwordField, values.system_user_password ?? ""],
  ];
  for (const [path, field, value] of checks) {
    if (path === "system_user_password" && value === "") continue;
    const result = field.safeParse(value);
    if (!result.success) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: [path], message: result.error.issues[0].message });
    }
  }
});

/**
 * `GET|POST /applications/{id}/root-lock`: whether the site folder is locked
 * against its own user. `unknown` is "could not check" (a filesystem with no
 * immutable flag), never "unlocked" — the two must not render the same.
 */
export const rootLockResponseSchema = z.object({
  root_lock: z.object({
    status: z.enum(["locked", "unlocked", "unknown"]).catch("unknown"),
    path: z.string().nullish(),
  }),
});
