import { z } from "zod";

/**
 * Connected git provider accounts.
 *
 * The token never appears in any of these shapes on purpose — it is write-only
 * at the API, so there is nothing to model on the way back.
 */

/**
 * One input on the connect form, described by the backend.
 *
 * The field list genuinely differs per provider (Bitbucket needs a workspace,
 * self-hosted GitLab needs a host), so the form is rendered from this rather
 * than hardcoded three times. A new provider then needs no frontend change.
 */
export const providerFieldSchema = z.object({
  name: z.string(),
  label: z.string(),
  required: z.boolean().default(false),
  type: z.string().default("text"),
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
  // Fetched from the provider during verification, never typed: the username
  // for GitHub/GitLab, the workspace slug for Bitbucket.
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

/**
 * Live token health, one row per account.
 *
 * `unknown` is not a soft failure — it means the provider could not be reached,
 * and the user must not be told to act on it.
 */
export const gitStatusSchema = z.object({
  id: z.number(),
  label: z.string().nullish(),
  provider: z.string().nullish(),
  provider_title: z.string().nullish(),
  status: z.enum(["valid", "invalid", "unknown"]).catch("unknown"),
  status_title: z.string().nullish(),
  expires_at: z.string().nullish(),
  // Absent for Bitbucket, which has no expiry at all — null means "there is
  // none", never "we could not tell".
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
  .min(1, "required")
  .max(60, "tooLong");

/**
 * The connect form's shape, built from the provider the user picked.
 *
 * Generated rather than written out because Zod strips keys it does not know
 * about: a hardcoded schema would silently drop `workspace` the moment the
 * backend added a provider that needs one.
 */
export function connectFormSchema(provider) {
  const shape = { label: labelSchema };

  for (const field of provider?.fields ?? []) {
    const base = z.string().trim();
    shape[field.name] = field.required ? base.min(1, "required") : base.optional();
  }

  return z.object(shape);
}

/** Rotation: the new credential and nothing else. */
export const replaceTokenSchema = z.object({
  token: z.string().trim().min(1, "required"),
});

/**
 * One repository the connected account can see.
 *
 * Fetched by the "Test repositories" action so we can show the user whether
 * their token actually reaches any repos without going through the app-create
 * flow.
 */
export const repositorySchema = z.object({
  full_name: z.string(),
  name: z.string(),
  private: z.boolean(),
  default_branch: z.string().nullish(),
  url: z.string(),
});

export const repositoriesResponseSchema = z.object({
  repositories: z.array(repositorySchema).default([]),
  // This endpoint deliberately returns a lightweight continuation marker,
  // not a total. It avoids an expensive provider-wide count request.
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
