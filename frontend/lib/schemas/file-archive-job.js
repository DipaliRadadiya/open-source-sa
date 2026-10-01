import { z } from "zod";

/** One queued compress or extract (runs outside the request time limit). */
export const fileArchiveJobSchema = z.object({
  id: z.number(),
  operation: z.enum(["compress", "extract"]),
  target: z.string(),
  status: z.enum(["queued", "running", "completed", "failed"]),
  size_bytes: z.number().nullish(),
  // Localized server-side from a stored reason code.
  message: z.string().nullish(),
  reference: z.string().nullish(),
  started_at: z.string().nullish(),
  finished_at: z.string().nullish(),
  created_at: z.string().nullish(),
});

export const archiveJobsResponseSchema = z.object({
  data: z.array(fileArchiveJobSchema),
});

export const ARCHIVE_IN_FLIGHT = ["queued", "running"];
