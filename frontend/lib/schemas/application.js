import { z } from "zod";

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
});

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
  installable_runtime: z.string().nullish(),
  has_installer: z.boolean().default(false),
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
});

// Read live from systemd on every request, so it is never stale — and absent
// entirely unless `has_process`.
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
  verification: z.string().nullish(),
  last_delivered_at: z.string().nullish(),
  last_delivered_at_human: z.string().nullish(),
}).passthrough();

export const applicationSchema = z.object({
  id: z.number(),
  name: z.string(),
  domain: z.string(),
  site_type: z.string(),
  site_type_title: z.string().nullish(),
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
  build_command: z.string().nullish(),
  start_command: z.string().nullish(),
  git_account_id: z.number().nullish(),
  repository: z.string().nullish(),
  repository_url: z.string().nullish(),
  branch: z.string().nullish(),
  settings: z.record(z.string(), z.unknown()).default({}),
  // Declared, or Zod strips them and the dashboard renders blanks for fields
  // the API is sending — the `disk_io` bug again.
  has_process: z.boolean().default(false),
  process: processSchema.nullish(),
  webhook: webhookSchema.nullish(),
  last_commit: z.union([z.string(), z.record(z.string(), z.unknown())]).nullish(),
  last_deployed_at: z.string().nullish(),
  last_deployed_at_human: z.string().nullish(),
  steps: z.array(z.string()).default([]),
  failed_step: z.string().nullish(),
  reference: z.string().nullish(),
  created_at: z.string().nullish(),
  created_at_human: z.string().nullish(),
});

export const applicationsResponseSchema = z.object({
  applications: z.array(applicationSchema).default([]),
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

export const createApplicationSchema = z.object({
  site_type: z.string().min(1, "applicationTypeRequired"),
  name: z.string().trim().min(1, "applicationNameRequired").max(255, "tooLong"),
  domain: z.string().trim().min(1, "applicationDomainRequired").max(255, "tooLong"),
  system_user_id: z.coerce.number().int().positive("applicationSystemUserRequired"),
}).passthrough();
