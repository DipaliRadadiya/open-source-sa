import { z } from "zod";

// A site's cron job that does not run on the site's PHP after a save (#5). Nothing in it has
// been changed: `new_command` is what switching would write, `suggested` the default tick.
export const cronjobPhpReviewSchema = z.object({
  id: z.number(),
  name: z.string(),
  command: z.string(),
  php_version: z.string().nullable(),
  suggested: z.boolean(),
  new_command: z.string(),
});

// The PHP save already succeeded, so a list we cannot read offers nothing rather than failing it.
export const cronjobsToReviewSchema = z.array(cronjobPhpReviewSchema).catch([]);

// Plain `php` jobs: they follow the server default, so a new default already moved them.
export const cronjobsOnDefaultSchema = z
  .array(z.object({ id: z.number(), name: z.string(), command: z.string(), application_id: z.number().nullable().optional() }))
  .catch([]);
