import { z } from "zod";

/**
 * `GET /applications/{id}/deployments` — the history and the settings in one
 * response, so the screen makes one request rather than two for facts the API
 * already returns together.
 *
 * Written against `DeploymentResource`, not the API reference: the doc's
 * example is stale in ways that matter — it calls the status `completed`
 * (really `succeeded`), the commit `commit` (really `commit_hash` with a
 * `commit_short`), and shows `output` on every row when the resource sends it
 * only on the detail view.
 */
export const DEPLOY_STATUSES = ["queued", "running", "succeeded", "failed"];

export const deploymentSchema = z
  .object({
    id: z.number(),
    status: z.string(),
    status_title: z.string().nullish(),
    // The API decides what counts as still-running, so the poll condition does
    // not have to be a list the frontend keeps in sync.
    in_flight: z.boolean().default(false),
    trigger: z.string().nullish(),
    trigger_title: z.string().nullish(),
    // Null for a webhook deploy — nobody pressed anything.
    user: z.object({ id: z.number(), username: z.string() }).nullable().optional(),
    branch: z.string().nullish(),
    commit_hash: z.string().nullish(),
    commit_short: z.string().nullish(),
    commit_message: z.string().nullish(),
    commit_author: z.string().nullish(),
    // Raw step keys ("script", "restart_app"). The API sends no titles for
    // these, unlike every other enum here, so nothing renders them yet.
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
    // What will actually run — the user's script, or the old build command it
    // still falls back to.
    deploy_script: z.string().nullish(),
    // False means `deploy_script` above is the fallback, not something they
    // wrote. The screen offers the default rather than presenting someone
    // else's text as theirs.
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

/**
 * The settings form.
 *
 * `webhook_enabled` is the write key even though the response calls the same
 * fact `auto_deploy` — `UpdateDeploySettingsRequest` accepts only `branch`,
 * `deploy_script` and `webhook_enabled`, so posting `auto_deploy` (as the API
 * reference's example does) is silently dropped.
 */
/**
 * How the process is started. Mirrors `UpdateApplicationRequest` plus the
 * backend's StartCommand rule: systemd execs `ExecStart` directly, so a shell
 * construct does not fail here — it fails at start time with an error naming
 * none of it.
 */
export const runtimeFormSchema = z.object({
  start_command: z
    .string()
    .trim()
    .min(1, "requiredField")
    .max(500, "max500")
    .refine((v) => !/(&&|\|\||[|;<>`&]|\$\()/.test(v), "shellInStartCommand"),
  // Blank means "pick a free one", which is a real answer rather than a
  // missing one — so an empty string passes and only a typed value is checked.
  app_port: z
    .string()
    .trim()
    .refine((v) => v === "" || /^\d+$/.test(v), "portNumber")
    .refine((v) => v === "" || (Number(v) >= 1024 && Number(v) <= 65535), "portRange1024")
    .default(""),
});

export const deploySettingsFormSchema = z.object({
  // A git ref, not free text: it lands in `git fetch origin <ref>`. Same
  // charset the backend allows.
  branch: z
    .string()
    .trim()
    .min(1, "requiredField")
    .max(255, "max255")
    .regex(/^[A-Za-z0-9._/-]+$/, "gitRef"),
  // Deliberately unvalidated beyond a length cap, matching the backend: it is
  // a shell script the user wrote to run as their own site user, and refusing
  // characters would be theatre.
  deploy_script: z.string().max(65535, "max65535").default(""),
  // The auto-deploy toggle is NOT here: the webhook card owns that fact and
  // saves it through its own endpoint. Two controls writing one flag is how
  // they end up disagreeing.
});
