import { z } from "zod";

// A permission entry embedded on a role (from GET/POST /admin/roles).
export const rolePermissionSchema = z.object({
  level: z.string(),
  name: z.string(),
  title: z.string().nullable().optional(),
  permissions: z.object({ view: z.boolean(), manage: z.boolean() }),
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
});

// Client form validation (name + description). The permission matrix is managed
// as separate component state and assembled into the request on submit.
export const roleFormSchema = z.object({
  name: z.string().min(1, "required_name").max(255, "tooLong"),
  description: z
    .string()
    .max(1000, "tooLong")
    .optional()
    .or(z.literal("")),
});
