import { z } from "zod";

// A single update run — returned by POST (202), the poll endpoint, and as
// `latest_run` on the state. Drive the progress bar from step_number/total_steps
// (never hardcode the step list); show the localized *_title fields as labels.
export const panelUpdateRunSchema = z
  .object({
    id: z.union([z.string(), z.number()]).transform(String),
    status: z.enum(["pending", "running", "succeeded", "failed"]).catch("running"),
    status_title: z.string().nullish(),
    current_step: z.string().nullish(),
    current_step_title: z.string().nullish(),
    step_number: z.number().int().nonnegative().catch(0),
    total_steps: z.number().int().nonnegative().catch(0),
    from_version: z.string().nullish(),
    to_version: z.string().nullish(),
    from_commit: z.string().nullish(),
    to_commit: z.string().nullish(),
    // On failure `reason` is the step that failed (stable key); reason_title is
    // the localized explanation. rolled_back means the previous version was restored.
    reason: z.string().nullish(),
    reason_title: z.string().nullish(),
    rolled_back: z.boolean().default(false),
    reference: z.string().nullish(),
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
    // null on an updated panel (checked out at a tag = detached HEAD) — expected.
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
    // false means the release host could not be reached — informational, NOT an error.
    checked: z.boolean().default(false),
  })
  .passthrough();

const preflightCheckSchema = z
  .object({
    key: z.string(),
    passed: z.boolean().default(false),
    detail: z.string().nullish(),
  })
  .passthrough();

export const panelUpdateStateSchema = z
  .object({
    installed: installedSchema.default({}),
    available: availableSchema.default({}),
    // false whenever either version is unknown — never prompt on a guess.
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
