import { z } from "zod";

export const cronjobPhpReviewSchema = z.object({
  id: z.number(),
  name: z.string(),
  command: z.string(),
  php_version: z.string().nullable(),
  suggested: z.boolean(),
  new_command: z.string(),
});

export const cronjobsToReviewSchema = z.array(cronjobPhpReviewSchema).catch([]);

export const cronjobsOnDefaultSchema = z
  .array(z.object({ id: z.number(), name: z.string(), command: z.string(), application_id: z.number().nullable().optional() }))
  .catch([]);
