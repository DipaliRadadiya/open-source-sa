import { z } from "zod";

export const brandingSchema = z.object({
  name: z.string(),
  logo: z.string().url(),
  logo_dark: z.string().url().nullable().optional(),
  icon: z.string().url().nullable().optional(),
  icon_dark: z.string().url().nullable().optional(),
  favicon: z.string().url(),
  primary_color: z.string(),
});
