import { z } from "zod";

const savedFail2banSchema = z.object({
  jail_name: z.string().nullish(),
  jail_content: z.string(),
  filter_content: z.string(),
});

export const applicationFail2banResponseSchema = z.object({
  fail2ban: savedFail2banSchema.nullable().default(null),
  jail_template: z.string().default(""),
  filter_template: z.string().default(""),
  filter_scope: z.string().nullish(),
  filter_note: z.string().nullish(),
});

export const applicationBansResponseSchema = z.object({
  jail: z.string().nullish(),
  banned: z.array(z.string()).default([]),
});

export const FAIL2BAN_PLACEHOLDERS = ["{name}", "{filter}", "{logpath}", "{slug}"];

export function missingPlaceholders(original = "", draft = "") {
  return FAIL2BAN_PLACEHOLDERS.filter(
    (token) => original.includes(token) && !draft.includes(token),
  );
}

const iniContent = z.string().trim().min(1, "requiredField").max(65535, "max65535");

export const fail2banConfigFormSchema = z.object({
  jail_config_content: iniContent,
  filter_config_content: iniContent,
});
