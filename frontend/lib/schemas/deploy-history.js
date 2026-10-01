import { z } from "zod";

/**
 * `GET /applications/{id}/deployments`: history and settings in one response.
 * Written against `DeploymentResource`, not the API reference: the status is
 * `succeeded` (not `completed`), the commit is `commit_hash`/`commit_short`,
 * and `output` is sent only on the detail view.
 */
export const DEPLOY_STATUSES = ["queued", "running", "succeeded", "failed"];

export const deploymentSchema = z
  .object({
    id: z.number(),
    status: z.string(),
    status_title: z.string().nullish(),
    // The API decides what counts as still running; poll on this.
    in_flight: z.boolean().default(false),
    trigger: z.string().nullish(),
    trigger_title: z.string().nullish(),
    // Null for a webhook deploy.
    user: z.object({ id: z.number(), username: z.string() }).nullable().optional(),
    branch: z.string().nullish(),
    commit_hash: z.string().nullish(),
    commit_short: z.string().nullish(),
    commit_message: z.string().nullish(),
    commit_author: z.string().nullish(),
    // Raw step keys ("script", "restart_app"); the API sends no titles for them.
    steps: z.array(z.string()).default([]),
    failed_step: z.string().nullish(),
    reference: z.string().nullish(),
    // Detail view only.
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
    // What will actually run: the user's script, or the legacy build command fallback.
    deploy_script: z.string().nullish(),
    // False means `deploy_script` is the fallback; the screen offers the default
    // instead of presenting it as the user's own.
    deploy_script_customised: z.boolean().default(false),
    default_deploy_script: z.string().nullish(),
    auto_deploy: z.boolean().default(false),
    webhook_enabled: z.boolean().nullish(),
    last_commit: z.string().nullish(),
    last_deployed_at: z.string().nullish(),
    last_deployed_at_human: z.string().nullish(),
    // Sent rather than hardcoded, the same way the cron presets are.
    placeholders: z.array(z.string()).default([]),
  })
  .passthrough();

export const deploymentsResponseSchema = z.object({
  deployments: z.array(deploymentSchema).default([]),
  settings: deploySettingsSchema.default({}),
});

export const deploymentResponseSchema = z.object({ deployment: deploymentSchema });

/** `GET /deployments/latest`: the newest row, the same shape as the history's. */
export const latestDeploymentResponseSchema = z.object({ latest: deploymentSchema.nullable() });

/**
 * How the process is started. Mirrors `UpdateApplicationRequest` plus the
 * backend's StartCommand rule: systemd execs `ExecStart` directly, so shell
 * constructs would fail only at start time.
 */
export const runtimeFormSchema = z.object({
  start_command: z
    .string()
    .trim()
    .min(1, "requiredField")
    .max(500, "max500")
    .refine((v) => !/(&&|\|\||[|;<>`&]|\$\()/.test(v), "shellInStartCommand"),
  // Blank means "pick a free one"; only a typed value is checked.
  app_port: z
    .string()
    .trim()
    .refine((v) => v === "" || /^\d+$/.test(v), "portNumber")
    .refine((v) => v === "" || (Number(v) >= 1024 && Number(v) <= 65535), "portRange1024")
    .default(""),
});

/**
 * The settings form. `UpdateDeploySettingsRequest` accepts only `branch`,
 * `deploy_script` and `webhook_enabled`; `auto_deploy` (the response's name
 * for the same fact) is silently dropped.
 */
export const deploySettingsFormSchema = z.object({
  // A git ref: it lands in `git fetch origin <ref>`. Same charset as the backend.
  branch: z
    .string()
    .trim()
    .min(1, "requiredField")
    .max(255, "max255")
    .regex(/^[A-Za-z0-9._/-]+$/, "gitRef"),
  // Only length-capped, matching the backend: a script the user runs as their
  // own site user.
  deploy_script: z.string().max(65535, "max65535").default(""),
  // The auto-deploy toggle is NOT here: the webhook card owns that flag and
  // saves it through its own endpoint.
});
