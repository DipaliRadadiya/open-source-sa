import { z } from "zod";

export const providerFieldSchema = z.object({
  name: z.string(),
  label: z.string(),
  required: z.boolean().default(false),
  type: z.string().default("text"),
  help: z.string().nullish(),
});

export const providerSchema = z.object({
  name: z.string(),
  title: z.string(),
  token_help: z.string().nullish(),
  fields: z.array(providerFieldSchema).default([]),
});

export const providersResponseSchema = z.object({
  providers: z.array(providerSchema).default([]),
});

export const gitAccountSchema = z.object({
  id: z.number(),
  provider: z.string(),
  provider_title: z.string(),
  label: z.string(),
  identifier: z.string().nullish(),
  host: z.string().nullish(),
  workspace: z.string().nullish(),
  scopes: z.array(z.string()).nullish(),
  last_verified_at: z.string().nullish(),
  last_verified_at_human: z.string().nullish(),
  created_at: z.string().nullish(),
  created_at_human: z.string().nullish(),
});

export const gitAccountsResponseSchema = z.object({
  git_accounts: z.array(gitAccountSchema).default([]),
});

export const gitStatusSchema = z.object({
  id: z.number(),
  label: z.string().nullish(),
  provider: z.string().nullish(),
  provider_title: z.string().nullish(),
  status: z.enum(["valid", "invalid", "unknown"]).catch("unknown"),
  status_title: z.string().nullish(),
  expires_at: z.string().nullish(),
  expires_in_days: z.number().nullish(),
  checked_at: z.string().nullish(),
});

export const gitStatusesResponseSchema = z.object({
  statuses: z.array(gitStatusSchema).default([]),
});

export const labelSchema = z
  .string()
  .trim()
  .min(1, "requiredField")
  .max(60, "tooLong");

export function connectFormSchema(provider) {
  const shape = { label: labelSchema };

  for (const field of provider?.fields ?? []) {
    const base = z.string().trim();
    shape[field.name] = field.required ? base.min(1, "requiredField") : base.optional();
  }

  return z.object(shape);
}

export const replaceTokenSchema = z.object({
  token: z.string().trim().min(1, "requiredField"),
});

export const repositorySchema = z.object({
  full_name: z.string(),
  name: z.string(),
  private: z.boolean(),
  default_branch: z.string().nullish(),
  url: z.string(),
});

export const repositoriesResponseSchema = z.object({
  repositories: z.array(repositorySchema).default([]),
  meta: z.object({
    page: z.number().optional(),
    has_more: z.boolean().optional(),
  }).optional(),
});

export const branchSchema = z.object({
  name: z.string(),
  protected: z.boolean().default(false),
});

export const branchesResponseSchema = z.object({
  branches: z.array(branchSchema).default([]),
});
