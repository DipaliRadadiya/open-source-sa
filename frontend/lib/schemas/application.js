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
  options: z
    .array(z.object({ value: z.string(), label: z.string() }))
    .default([]),
  source: z.string().nullish(),
  depends_on: z.string().nullish(),
  generate: z.boolean().default(false),
  // package_manager → install+build command. PHP sends an empty map as [].
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
  // Read by `acceptedEngines` (database-readiness.js). `[]` is a real answer: no installer, no list.
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

export const systemUserOptionSchema = z
  .object({
    id: z.number(),
    username: z.string(),
  })
  .passthrough();

export const systemUsersResponseSchema = z.object({
  system_users: z.array(systemUserOptionSchema).default([]),
  // Null when sshd could not be asked, which is not the same as "no".
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
  // False means the URL and secret must be pasted in by hand.
  registered: z.boolean().default(false),
  last_delivered_at: z.string().nullish(),
  last_delivered_at_human: z.string().nullish(),
}).passthrough();

// `message` arrives translated and is shown as sent; re-wording it would make screens disagree.
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
  // Nullable since domains moved to their own table; required would reject the whole list.
  domain: z.string().nullish(),
  // Decided by the server (`http://` until a certificate is servable). Never assemble `https://{domain}`.
  url: z.string().nullish(),
  site_type: z.string(),
  site_type_title: z.string().nullish(),
  // Populated only once Detect is pressed. `suggested` is pre-validated, so accepting it cannot 422.
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
  // What a container site is wired to. Declared here as well as on the detail
  // payload because the Docker page's attach dialog reads the LIST: without
  // these, Zod strips them and every site looks unattached — so the dialog would
  // offer to attach a site to the network it is already on.
  docker_network: z.string().nullish(),
  volume_mounts: z
    .array(z.object({ volume: z.string(), path: z.string() }))
    .nullish()
    .transform((mounts) => mounts ?? []),
  // Names only. Enough to know whether to offer a Credentials section and what to
  // label each row; the values are fetched on demand from their own endpoint.
  container_secret_keys: z
    .array(z.string())
    .nullish()
    .transform((keys) => keys ?? []),
  // Whether a person has confirmed they saved them. **Defaults to true when
  // absent**, which is the safe direction: a missing field must not put a
  // first-run card full of passwords on the dashboard of a site that has been
  // running for a year. Zod strips what it does not declare, so this being here
  // at all is what makes the card possible.
  credentials_acknowledged: z
    .boolean()
    .nullish()
    .transform((seen) => seen ?? true),
  rendering_type: z.string().nullish(),
  status: z
    .enum(["pending", "provisioning", "active", "failed"])
    .catch("pending"),
  status_title: z.string().nullish(),
  deployed: z.boolean().default(false),
  system_user: systemUserOptionSchema.nullish(),
  php_version: z.string().nullish(),
  node_version: z.string().nullish(),
  app_port: z.number().nullish(),
  web_root: z.string().nullish(),
  // The served directory; the deploy script's `{path}` expands to this, not to `path`.
  document_root: z.string().nullish(),
  // The code's directory (parent of a fixed web root like Laravel's `public`); cron resolves from it.
  path: z.string().nullish(),
  build_command: z.string().nullish(),
  start_command: z.string().nullish(),
  git_account_id: z.number().nullish(),
  repository: z.string().nullish(),
  repository_url: z.string().nullish(),
  branch: z.string().nullish(),
  // PHP serializes an empty map as `[]`; coerce it, or the whole list fails to parse.
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
  // False when the app uses the Authorization header, which Basic Auth would consume.
  basic_auth_supported: z.boolean().default(true),
  // OpenLiteSpeed has no equivalent of the nginx WAF rule set.
  waf_supported: z.boolean().default(true),
  // When the CURRENT provisioning run started; `created_at` is wrong after a retry.
  provisioning_started_at: z.string().nullish(),
  provisioning_started_at_human: z.string().nullish(),
  is_disabled: z.boolean().default(false),
  disabled_at: z.string().nullish(),
  // "A jail is configured", not "fail2ban is protecting this site"; service state is server-wide.
  fail2ban_enabled: z.boolean().default(false),
  ai_bot_policy: z.string().nullish(),
  ai_bot_policy_title: z.string().nullish(),
  // Per-bot overrides; `whenLoaded`, so currently only returned by the bot-blocker PUT.
  bot_blocked: z.array(z.string()).default([]),
  bot_allowed: z.array(z.string()).default([]),
  // npm | yarn | pnpm | bun, for the ssr/csr rendering types.
  package_manager: z.string().nullish(),
  is_staging: z.boolean().default(false),
  production_application_id: z.number().nullish(),
  has_staging: z.boolean().default(false),
  cloned_from_application_id: z.number().nullish(),
  // `waf_exceptions`/`waf_custom_rules` come only from GET .../waf. NOT defaulted: absent means "not loaded".
  waf_enabled: z.boolean().default(false),
  waf_mode: z.string().nullish(),
  waf_mode_title: z.string().nullish(),
  waf_categories: z.array(z.string()).default([]),
  waf_exceptions: z.array(z.string()).optional(),
  waf_custom_rules: z.array(z.string()).optional(),
  last_commit: z.union([z.string(), z.record(z.string(), z.unknown())]).nullish(),
  // What is on disk: a deploy failing after checkout leaves the new commit live while `last_commit`
  // names the old one. Single application only.
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
  // The git account was deleted: no credential, so the next deploy fails.
  git_account_missing: z.boolean().nullish(),
  failed_reason: z.string().nullish(),
  // Set only when the cause is identified, already localized; fall back to the step otherwise.
  failed_reason_title: z.string().nullish(),
  reference: z.string().nullish(),
  // The container fields. Undeclared fields are stripped by this non-passthrough
  // object, and the Container screen then saves the blanks back over real values.
  slug: z.string().nullish(),
  image: z.string().nullish(),
  container_port: z.number().nullish(),
  memory_limit: z.string().nullish(),
  cpu_limit: z.string().nullish(),
  registry_id: z.number().nullish(),
  // Shown in the sites list; must be declared or Zod strips them.
  directory_size_bytes: z.number().nullish(),
  // The volumes' share of the total. Nullish is meaningful: absent means the site
  // has no volumes to measure, and the dashboard says nothing about them.
  volume_size_bytes: z.number().nullish(),
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

// `config/ai_bots.php` feeds both this endpoint and the vhost; render from the response.
export const aiBotPolicySchema = z.object({
  title: z.string(),
  description: z.string(),
  blocked_bots: z.array(z.string()).default([]),
  blocked_count: z.number().default(0),
  // Whether the robots.txt lines below also apply to this choice.
  robots_txt_recommended: z.boolean().default(false),
});

export const aiBotPoliciesResponseSchema = z.object({
  ai_bot_policies: z.record(z.string(), aiBotPolicySchema).default({}),
  // Google's and Apple's AI-training opt-out, which only robots.txt can express;
  // the panel shows it for the owner to add and never writes the file.
  robots_txt: z
    .object({ note: z.string().default(""), lines: z.string().default("") })
    .nullish(),
});

// `unavailable` (log unreadable) is NOT `empty`; never show it as "no bots".
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

// Titles only: per-category hints live in the message files.
export const wafOptionSchema = z.object({
  value: z.string(),
  title: z.string(),
});

export const wafOptionsResponseSchema = z.object({
  waf_categories: z.array(wafOptionSchema).default([]),
  waf_modes: z.array(wafOptionSchema).default([]),
});

// `web_server` gates the firewall screen (no OpenLiteSpeed 8G ruleset). `server_ip` and
// `temporary_domain_suffixes`: where sites point, and which wildcard-DNS host resolves it.
export const serverCapabilitiesResponseSchema = z.object({
  capabilities: z.object({
    stack: z.string().nullish(),
    web_server: z.string().nullish(),
    server_ip: z.string().nullish(),
    temporary_domain_suffixes: z.array(z.string()).default([]),
  }),
});

// The API takes username and password together when `enabled`, so both are required only then.
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
      // Browsers encode a non-ASCII name differently, so it could be saved and never sign in.
      ctx.addIssue({ path: ["username"], code: "custom", message: "securityUsernameAscii" });
    }
    if (!data.password) {
      ctx.addIssue({
        path: ["password"],
        code: "custom",
        message: "required_password",
      });
    } else if (data.password.length < 8) {
      ctx.addIssue({ path: ["password"], code: "custom", message: "min8" });
    } else if (data.password.length > 255) {
      ctx.addIssue({ path: ["password"], code: "custom", message: "max255" });
    }
  });

const applicationDomainLabel = /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/;

/** A hostname only — no protocol, path, query, credentials, or port. */
export function isValidApplicationDomain(value) {
  const domain = String(value ?? "")
    .trim()
    .toLowerCase();
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

// Never mutates input silently: the UI renders an explicit "Use …" action.
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
  // Required only when picking an existing user; `superRefine` puts the message on the control.
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

// `unknown` is "could not check" (no immutable flag), never "unlocked"; render them differently.
export const rootLockResponseSchema = z.object({
  root_lock: z.object({
    status: z.enum(["locked", "unlocked", "unknown"]).catch("unknown"),
    path: z.string().nullish(),
  }),
});
