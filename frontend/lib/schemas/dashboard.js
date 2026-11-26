import { z } from "zod";

export const dashboardSchema = z.object({
  users: z.object({
    total: z.number(),
    admins: z.number(),
    non_admins: z.number(),
  }),
  roles: z.object({
    total: z.number(),
  }),
  activity: z.object({
    today: z.number(),
    total: z.number(),
  }),
});
