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
  required_if: z.record(z.string(), z.array(z.string())).nullish(),
  generate: z.boolean().default(false),
  build_templates: z
    .preprocess((v) => (Array.isArray(v) ? {} : v), z.record(z.string(), z.string()))
    .optional(),
});

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
  unavailable_code: z.string().nullish(),
  accepted_engines: z.array(z.string()).nullish(),
  installable_runtime: z.string().nullish(),
  has_installer: z.boolean().default(false),
  php_version_range: versionRange,
  node_version_range: versionRange,
  fields: z.array(textField).default([]),
  // Must be declared or Zod strips it.
  recipe: z.object({
    slug: z.string(),
    version: z.number(),
    description: z.string().nullish(),
    services: z.array(z.object({ name: z.string(), role: z.string() })).default([]),
    volumes: z.array(z.object({ role: z.string(), path: z.string() })).default([]),
    images: z.array(z.object({
      role: z.string(),
      ref: z.string(),
      tested: z.array(z.string()).default([]),
    })).default([]),
    memory_floor: z.string().nullish(),
    first_run: z.object({
      kind: z.string(),
      credential_labels: z.array(z.string()).default([]),
    }).nullish(),
    inputs: z.array(z.string()).default([]),
    docs_url: z.string().nullish(),
    icon_data_uri: z.string().nullish(),
  }).nullish(),
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
  meta: listMetaSchema.extend({ ssh_access_enforced: z.boolean().nullable().optional() }),
});

export const processSchema = z.object({
  state: z.string().nullish(),
  sub_state: z.string().nullish(),
  since: z.string().nullish(),
  memory: z.union([z.number(), z.string()]).nullish(),
  cpu_percent: z.number().nullish(),
  pid: z.number().nullish(),
  restarts: z.number().nullish(),
}).passthrough();

const webhookSchema = z.object({
  enabled: z.boolean().default(false),
  provider: z.string().nullish(),
  url: z.string().nullish(),
  secret: z.string().nullish(),
  verification: z.string().nullish(),
  registered: z.boolean().default(false),
  last_delivered_at: z.string().nullish(),
  last_delivered_at_human: z.string().nullish(),
}).passthrough();

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

export const containerRedeploySchema = z.object({
  id: z.number().nullish(),
  status: z.string().nullish(),
  in_flight: z.boolean().catch(false),
  failed_step: z.string().nullish(),
  failed_reason_title: z.string().nullish(),
  reference: z.string().nullish(),
  finished_at_human: z.string().nullish(),
});

export const applicationSchema = z.object({
  id: z.number(),
  name: z.string(),
  domain: z.string().nullish(),
  // Comes from the server (http until SSL works). Don't build https://{domain} yourself.
  url: z.string().nullish(),
  site_type: z.string(),
  site_type_title: z.string().nullish(),
  origin: z.object({
    kind: z.enum(["recipe", "image", "compose", "other"]).catch("other"),
    recipe: z.object({
      slug: z.string(),
      title: z.string(),
      version: z.number(),
      deprecated: z.boolean().default(false),
    }).nullish(),
    image: z.string().nullish(),
  }).nullish(),
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
  // The Docker attach dialog reads these from the list, so they must be declared here too.
  docker_network: z.string().nullish(),
  volume_mounts: z
    .array(z.object({ volume: z.string(), path: z.string() }))
    .nullish()
    .transform((mounts) => mounts ?? []),
  container_secret_keys: z
    .array(z.string())
    .nullish()
    .transform((keys) => keys ?? []),
  // Defaults to true so an old site never shows the first-run password card.
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
  php_binary: z.string().nullish(),
  node_version: z.string().nullish(),
  node_version_change: z
    .object({
      target: z.string().nullish(),
      status: z.enum(["switching", "failed"]).catch("switching"),
      reason: z.string().nullish(),
      message: z.string().nullish(),
      reference: z.string().nullish(),
    })
    .nullish(),
  app_port: z.number().nullish(),
  web_root: z.string().nullish(),
  document_root: z.string().nullish(),
  path: z.string().nullish(),
  build_command: z.string().nullish(),
  start_command: z.string().nullish(),
  git_account_id: z.number().nullish(),
  repository: z.string().nullish(),
  repository_url: z.string().nullish(),
  branch: z.string().nullish(),
  // PHP sends an empty map as [], which would fail the whole list.
  settings: z.preprocess(
    (value) => (Array.isArray(value) ? {} : value),
    z.record(z.string(), z.unknown()).default({}),
  ),
  // Must be declared; this object doesn't passthrough.
  has_process: z.boolean().default(false),
  process: processSchema.nullish(),
  webhook: webhookSchema.nullish(),
  basic_auth_enabled: z.boolean().default(false),
  basic_auth_username: z.string().nullish(),
  basic_auth_unencrypted: z.boolean().default(false),
  basic_auth_supported: z.boolean().default(true),
  waf_supported: z.boolean().default(true),
  provisioning_started_at: z.string().nullish(),
  provisioning_started_at_human: z.string().nullish(),
  is_disabled: z.boolean().default(false),
  disabled_at: z.string().nullish(),
  fail2ban_enabled: z.boolean().default(false),
  ai_bot_policy: z.string().nullish(),
  ai_bot_policy_title: z.string().nullish(),
  bot_blocked: z.array(z.string()).default([]),
  bot_allowed: z.array(z.string()).default([]),
  package_manager: z.string().nullish(),
  is_staging: z.boolean().default(false),
  production_application_id: z.number().nullish(),
  has_staging: z.boolean().default(false),
  cloned_from_application_id: z.number().nullish(),
  waf_enabled: z.boolean().default(false),
  waf_mode: z.string().nullish(),
  waf_mode_title: z.string().nullish(),
  waf_categories: z.array(z.string()).default([]),
  waf_exceptions: z.array(z.string()).optional(),
  waf_custom_rules: z.array(z.string()).optional(),
  last_commit: z.union([z.string(), z.record(z.string(), z.unknown())]).nullish(),
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
  git_account_missing: z.boolean().nullish(),
  failed_reason: z.string().nullish(),
  failed_reason_title: z.string().nullish(),
  reference: z.string().nullish(),
  slug: z.string().nullish(),
  image: z.string().nullish(),
  container_port: z.number().nullish(),
  memory_limit: z.string().nullish(),
  cpu_limit: z.string().nullish(),
  registry_id: z.number().nullish(),
  container_status: z.string().nullish(),
  health_warnings: z.array(z.object({ service: z.string(), state: z.string() })).catch([]),
  container_redeploy: containerRedeploySchema.nullish().catch(null),
  last_failure: z
    .object({
      reason: z.string().catch(""),
      message: z.string().nullish(),
      last_line: z.string().nullish(),
      log: z.string().nullish(),
      at: z.string().nullish(),
    })
    .nullish()
    .catch(null),
  // Must be declared or Zod strips it.
  directory_size_bytes: z.number().nullish(),
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

export const aiBotPolicySchema = z.object({
  title: z.string(),
  description: z.string(),
  blocked_bots: z.array(z.string()).default([]),
  blocked_count: z.number().default(0),
  robots_txt_recommended: z.boolean().default(false),
});

export const aiBotPoliciesResponseSchema = z.object({
  ai_bot_policies: z.record(z.string(), aiBotPolicySchema).default({}),
  robots_txt: z
    .object({ note: z.string().default(""), lines: z.string().default("") })
    .nullish(),
});

// unavailable (log unreadable) is not the same as empty.
export const botTrafficBotSchema = z.object({
  bot: z.string(),
  hits: z.number().default(0),
  category: z.string().nullish(),
  blocked: z.boolean().default(false),
  blocked_hits: z.number().default(0),
  last_status: z.number().nullish(),
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

export const wafOptionSchema = z.object({
  value: z.string(),
  title: z.string(),
});

export const wafOptionsResponseSchema = z.object({
  waf_categories: z.array(wafOptionSchema).default([]),
  waf_modes: z.array(wafOptionSchema).default([]),
});

export const serverCapabilitiesResponseSchema = z.object({
  capabilities: z.object({
    stack: z.string().nullish(),
    web_server: z.string().nullish(),
    server_ip: z.string().nullish(),
    temporary_domain_suffixes: z.array(z.string()).default([]),
    capabilities: z
      .object({ serving_profiles: z.array(z.string()).nullish() })
      .partial()
      .nullish(),
  }),
});

export const securityFormSchema = z
  .object({
    enabled: z.boolean(),
    username: z.string(),
    password: z.string(),
  })
  .superRefine((data, ctx) => {
    if (!data.enabled) return;
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
      // Browsers encode non-ASCII names differently, so the login could never work.
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
const applicationDomainTld = /^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/;

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
  return (
    labels.length >= 2 &&
    labels.every((label) => applicationDomainLabel.test(label)) &&
    applicationDomainTld.test(labels[labels.length - 1])
  );
}

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
  name: z.string().trim().min(1, "applicationNameRequired").max(240, "max240"),
  domain: z
    .string()
    .trim()
    .min(1, "applicationDomainRequired")
    .max(255, "tooLong")
    .refine(isValidApplicationDomain, "hostnameInvalid"),
  generate_system_user: z.boolean().default(true),
  system_user_id: z.union([z.coerce.number().int().positive(), z.literal("")]).optional(),
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

// unknown means couldn't check, not unlocked.
export const rootLockResponseSchema = z.object({
  root_lock: z.object({
    status: z.enum(["locked", "unlocked", "unknown"]).catch("unknown"),
    path: z.string().nullish(),
  }),
});
