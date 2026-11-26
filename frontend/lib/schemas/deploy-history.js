import { z } from "zod";

export const deploymentSchema = z
  .object({
    id: z.number(),
    status: z.string(),
    status_title: z.string().nullish(),
    in_flight: z.boolean().default(false),
    trigger: z.string().nullish(),
    trigger_title: z.string().nullish(),
    user: z.object({ id: z.number(), username: z.string() }).nullable().optional(),
    branch: z.string().nullish(),
    commit_hash: z.string().nullish(),
    commit_short: z.string().nullish(),
    commit_message: z.string().nullish(),
    commit_author: z.string().nullish(),
    steps: z.array(z.string()).default([]),
    failed_step: z.string().nullish(),
    reference: z.string().nullish(),
    output: z.string().nullish(),
    duration: z.union([z.number(), z.string()]).nullish(),
    started_at: z.string().nullish(),
    finished_at: z.string().nullish(),
    created_at: z.string().nullish(),
    created_at_human: z.string().nullish(),
  })
  .passthrough();

export const deploySettingsSchema = z
  .object({
    branch: z.string().nullish(),
    repository: z.string().nullish(),
    deploy_script: z.string().nullish(),
    deploy_script_customised: z.boolean().default(false),
    default_deploy_script: z.string().nullish(),
    auto_deploy: z.boolean().default(false),
    webhook_enabled: z.boolean().nullish(),
    last_commit: z.string().nullish(),
    last_deployed_at: z.string().nullish(),
    last_deployed_at_human: z.string().nullish(),
    placeholders: z.array(z.string()).default([]),
    // PHP sends an empty map as [].
    placeholder_values: z
      .preprocess((v) => (Array.isArray(v) ? {} : v), z.record(z.string(), z.string().nullable()))
      .default({}),
  })
  .passthrough();

export const deploymentsResponseSchema = z.object({
  deployments: z.array(deploymentSchema).default([]),
  settings: deploySettingsSchema.default({}),
});

export const deploymentResponseSchema = z.object({ deployment: deploymentSchema });

export const latestDeploymentResponseSchema = z.object({ latest: deploymentSchema.nullable() });

// systemd runs ExecStart directly, so shell syntax would only fail at start.
export const runtimeFormSchema = z.object({
  start_command: z
    .string()
    .trim()
    .min(1, "requiredField")
    .max(500, "max500")
    .refine((v) => !/(&&|\|\||[|;<>`&]|\$\()/.test(v), "shellInStartCommand"),
  app_port: z
    .string()
    .trim()
    .refine((v) => v === "" || /^\d+$/.test(v), "portNumber")
    .refine((v) => v === "" || (Number(v) >= 1024 && Number(v) <= 65535), "portRange1024")
    .default(""),
});

export const deploySettingsFormSchema = z.object({
  branch: z
    .string()
    .trim()
    .min(1, "requiredField")
    .max(255, "max255")
    .regex(/^[A-Za-z0-9._/-]+$/, "gitRef"),
  deploy_script: z.string().max(65535, "max65535").default(""),
});
