import { z } from "zod";

/**
 * The three states a grant can hold.
 *
 * The pivot stores two booleans, but only three of their four combinations
 * are reachable — "manage without view" rewrites itself — so the form offers
 * one three-way choice rather than two checkboxes whose fourth combination
 * silently changes under the user.
 */
export const ACCESS_NONE = "none";
export const ACCESS_VIEW = "view";
export const ACCESS_MANAGE = "manage";

// Collapse a stored pair into the level it represents. `manage` is checked
// first: a legacy row with manage=true, view=false grants management, and
// reporting it as "no access" would show something weaker than the truth.
export function accessFromGrant(view, manage) {
  if (manage) return ACCESS_MANAGE;
  if (view) return ACCESS_VIEW;
  return ACCESS_NONE;
}

// Titles and order come from the server — never hardcoded here, and never
// re-sorted, so a fourth level or a renamed one needs no frontend change.
export const accessLevelSchema = z.object({
  key: z.string(),
  title: z.string(),
  description: z.string().default(""),
});

// A permission entry embedded on a role (from GET/POST /admin/roles).
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
  // `access` is authoritative; the boolean pair is only read when an older
  // backend omits it, so one shape is handled everywhere downstream.
  .transform((entry) => ({
    ...entry,
    access:
      entry.access ??
      accessFromGrant(
        Boolean(entry.permissions?.view),
        Boolean(entry.permissions?.manage),
      ),
  }));

// One section of the role form: the catalog already bucketed by the server.
// Keyed on level AND sub_level — `logs` exists at both server and application
// level as two different permissions, and merging them on sub_level alone
// would offer a single control over two unrelated grants.
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
