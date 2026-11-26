import { z } from "zod";

export const panelUpdateRunSchema = z
  .object({
    id: z.union([z.string(), z.number()]).transform(String),
    status: z.enum(["pending", "running", "succeeded", "failed"]).catch("running"),
    status_title: z.string().nullish(),
    current_step: z.string().nullish(),
    current_step_title: z.string().nullish(),
    step_number: z.number().int().nonnegative().catch(0),
    total_steps: z.number().int().nonnegative().catch(0),
    dry_run: z.boolean().nullish(),
    from_version: z.string().nullish(),
    to_version: z.string().nullish(),
    from_commit: z.string().nullish(),
    to_commit: z.string().nullish(),
    reason: z.string().nullish(),
    reason_title: z.string().nullish(),
    // Must stay nullish: the API sends null until the run finishes.
    rolled_back: z.boolean().nullish().transform((value) => value ?? false),
    reference: z.string().nullish(),
    output: z.string().catch(""),
    output_truncated: z.boolean().catch(false),
    started_at: z.string().nullish(),
    started_at_human: z.string().nullish(),
    finished_at: z.string().nullish(),
    finished_at_human: z.string().nullish(),
  })
  .passthrough();

const installedSchema = z
  .object({
    version: z.string().nullish(),
    commit_hash: z.string().nullish(),
    commit_short: z.string().nullish(),
    branch: z.string().nullish(),
    source: z.string().nullish(),
    is_git_checkout: z.boolean().default(false),
    has_local_changes: z.boolean().nullish(),
  })
  .passthrough();

const availableSchema = z
  .object({
    version: z.string().nullish(),
    published_at: z.string().nullish(),
    notes: z.string().nullish(),
    url: z.string().nullish(),
    checked: z.boolean().default(false),
  })
  .passthrough();

const preflightCheckSchema = z
  .object({
    key: z.string(),
    passed: z.boolean().default(false),
    detail: z.string().nullish(),
    message: z.string().nullish(),
    values: z.record(z.string(), z.number().nullish()).nullish(),
    advisory: z.boolean().default(false),
  })
  .passthrough();

export const panelUpdateStateSchema = z
  .object({
    installed: installedSchema.default({}),
    available: availableSchema.default({}),
    update_available: z.boolean().default(false),
    preflight: z
      .object({
        ready: z.boolean().default(false),
        checks: z.array(preflightCheckSchema).default([]),
      })
      .default({ ready: false, checks: [] }),
    latest_run: panelUpdateRunSchema.nullish(),
  })
  .passthrough();
