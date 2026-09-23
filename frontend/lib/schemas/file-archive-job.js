import { z } from "zod";

/**
 * One queued compress or extract.
 *
 * Compressing used to run inside the request against a ceiling every file
 * operation shared, so a large selection could not finish — and the failure
 * was reported as a filename collision, because the killed `tar` left a
 * partial archive the retry then tripped over.
 */
export const fileArchiveJobSchema = z.object({
  id: z.number(),
  operation: z.enum(["compress", "extract"]),
  target: z.string(),
  status: z.enum(["queued", "running", "completed", "failed"]),
  size_bytes: z.number().nullish(),
  // Built server-side in the viewer's locale, because the reason is stored as
  // a code rather than a finished sentence.
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
