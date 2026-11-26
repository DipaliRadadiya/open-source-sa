import { z } from "zod";
import { applicationSchema, isValidApplicationDomain } from "./application.js";

export const stagingRunSchema = z.object({
  id: z.number(),
  kind: z.string(),
  mode: z.string().nullish(),
  backup: z.boolean().nullish(),
  safety_copy: z.boolean().nullish(),
  status: z.string(),
  error: z
    .object({
      message: z.string().nullish(),
      code: z.string().nullish(),
      reason: z.string().nullish(),
      reference: z.string().nullish(),
    })
    .passthrough()
    .nullish(),
  error_status: z.number().nullish(),
  backup_id: z.number().nullish(),
  started_at_human: z.string().nullish(),
  finished_at_human: z.string().nullish(),
});

export const stagingRunActive = (run) => run?.status === "queued" || run?.status === "running";

export function runErrorText(error) {
  const field = Object.values(error?.errors ?? {}).flat().find((text) => typeof text === "string" && text);
  return field ?? error?.message ?? null;
}

export const applicationStagingResponseSchema = z.object({
  staging: applicationSchema.nullable().default(null),
  latest_run: stagingRunSchema.nullish(),
});

export const createStagingFormSchema = z.object({
  domain: z
    .string()
    .trim()
    .toLowerCase()
    .min(1, "stagingDomainRequired")
    .max(255, "max255")
    .refine(isValidApplicationDomain, "stagingDomainInvalid"),
});

// No default: files runs rsync --delete, database can break production, pre-push dumps can't be restored.
export const PUSH_MODES = ["files", "database", "full"];

export const pushStagingFormSchema = z.object({
  mode: z.enum(PUSH_MODES, { message: "modeRequired" }),
});

export const stagingSafetyCopiesSchema = z.object({
  safety_copies: z
    .array(z.object({ name: z.string(), size_bytes: z.number().nullish(), created_at: z.string().nullish() }))
    .default([]),
  kept: z.number().nullish(),
});
