import { z } from "zod";

export const LOG_GROUPS = ["web", "database", "php", "system", "security", "daemon"];

// Line counts the API accepts; 5000 is its hard cap.
export const LINE_OPTIONS = [100, 200, 500, 1000, 5000];

export const logSourceSchema = z.object({
  key: z.string(),
  label: z.string(),
  group: z.string(),
  size: z.number().nullable().optional(),
  modified: z.string().nullable().optional(),
  readable: z.boolean(),
});

export const logSourcesResponseSchema = z.object({
  logs: z.array(logSourceSchema),
});

export const logReadSchema = z.object({
  key: z.string(),
  label: z.string(),
  group: z.string(),
  lines: z.array(z.string()),
  // Byte offset to pass back as `after` when tailing.
  cursor: z.number(),
  truncated: z.boolean().optional(),
});

export const logReadResponseSchema = z.object({ log: logReadSchema });
