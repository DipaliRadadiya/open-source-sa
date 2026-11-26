import { z } from "zod";
import { listMetaSchema } from "./list.js";

export const ACCESS_NONE = "none";
export const ACCESS_VIEW = "view";
export const ACCESS_MANAGE = "manage";

export function accessFromGrant(view, manage) {
  if (manage) return ACCESS_MANAGE;
  if (view) return ACCESS_VIEW;
  return ACCESS_NONE;
}

export const accessLevelSchema = z.object({
  key: z.string(),
  title: z.string(),
  description: z.string().default(""),
});

export const rolePermissionSchema = z
  .object({
    level: z.string(),
    name: z.string(),
    title: z.string().nullable().optional(),
    access: z.enum([ACCESS_NONE, ACCESS_VIEW, ACCESS_MANAGE]).optional(),
    permissions: z
      .object({ view: z.boolean(), manage: z.boolean() })
      .optional(),
  })
  .transform((entry) => ({
    ...entry,
    access:
      entry.access ??
      accessFromGrant(
        Boolean(entry.permissions?.view),
        Boolean(entry.permissions?.manage),
      ),
  }));

export const permissionGroupSchema = z.object({
  level: z.string().default(""),
  sub_level: z.string().default(""),
  sub_level_title: z.string().nullable().optional(),
  permissions: z.array(z.object({}).passthrough()).default([]),
});

export const roleSchema = z.object({
  id: z.number(),
  name: z.string(),
  slug: z.string().nullable().optional(),
  is_system: z.boolean().default(false),
  description: z.string().nullable().optional(),
  permissions: z.array(rolePermissionSchema).default([]),
  created_at: z.string().nullable().optional(),
  created_at_human: z.string().nullable().optional(),
});

export const rolesResponseSchema = z.object({
  roles: z.array(roleSchema),
  meta: listMetaSchema,
});

export const roleFormSchema = z.object({
  name: z.string().min(1, "required_name").max(255, "tooLong"),
  description: z
    .string()
    .max(1000, "tooLong")
    .optional()
    .or(z.literal("")),
});
