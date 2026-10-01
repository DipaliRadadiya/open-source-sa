import { z } from "zod";

// The token is write-only at the API, so it never appears in these shapes.

// Fields differ per provider; the form renders from this, so new providers need no frontend change.
export const providerFieldSchema = z.object({
  name: z.string(),
  label: z.string(),
  required: z.boolean().default(false),
  type: z.string().default("text"),
  // Per-field guidance, localized by the backend. Null when self-explanatory.
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
  // From the provider: the GitHub/GitLab username or the Bitbucket workspace slug.
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

// `unknown` means the provider could not be reached; never tell the user to act on it.
export const gitStatusSchema = z.object({
  id: z.number(),
  label: z.string().nullish(),
  provider: z.string().nullish(),
  provider_title: z.string().nullish(),
  status: z.enum(["valid", "invalid", "unknown"]).catch("unknown"),
  status_title: z.string().nullish(),
  expires_at: z.string().nullish(),
  // Null means no expiry (e.g. Bitbucket), never "could not tell".
  expires_in_days: z.number().nullish(),
  checked_at: z.string().nullish(),
});

export const gitStatusesResponseSchema = z.object({
  statuses: z.array(gitStatusSchema).default([]),
});

/** A name the user chooses; it is how the account is identified everywhere. */
export const labelSchema = z
  .string()
  .trim()
  .min(1, "requiredField")
  .max(60, "tooLong");

// Built from the provider's fields so provider-specific keys are not stripped.
export function connectFormSchema(provider) {
  const shape = { label: labelSchema };

  for (const field of provider?.fields ?? []) {
    const base = z.string().trim();
    shape[field.name] = field.required ? base.min(1, "requiredField") : base.optional();
  }

  return z.object(shape);
}

/** Rotation: the new credential and nothing else. */
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
  // A continuation marker, not a total (avoids a provider-wide count).
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
