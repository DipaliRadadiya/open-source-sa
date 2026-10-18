import { z } from "zod";
import { isIpAddress } from "../validation/ip.js";

// No byte cursor (the client re-reads the last N lines) and no group or readable flag.
// `kind` is file | journal | container.
export const applicationLogSourceSchema = z
  .object({
    key: z.string(),
    label: z.string(),
    kind: z.string().nullish(),
    // false is normal: a site nobody has visited has no access log yet.
    exists: z.boolean().default(false),
    // Null for a journal or container, a file not written yet, or a failed stat: unknown, not 0.
    size: z.number().nullish(),
    modified: z.string().nullish(),
  })
  .passthrough();

export const applicationLogsResponseSchema = z.object({
  logs: z.array(applicationLogSourceSchema).default([]),
});

export const applicationLogSchema = z
  .object({
    key: z.string(),
    label: z.string().nullish(),
    kind: z.string().nullish(),
    exists: z.boolean().default(false),
    lines: z.array(z.string()).default([]),
    // Where a `from` read started; null for a read of the last lines.
    first_line: z.number().nullish(),
    truncated: z.boolean().default(false),
    // Whether a FILTERED read hit the line cap: distinguishes "not in your log"
    // from "only the end was searched". Distinct from `truncated`.
    search_window_capped: z.boolean().default(false),
  })
  .passthrough();

export const applicationLogResponseSchema = z.object({
  log: applicationLogSchema,
});

export const LOG_METHODS = ["GET", "POST", "PUT", "PATCH", "DELETE", "HEAD", "OPTIONS"];

// The API's own rules (ShowApplicationLogRequest), so a filter never round-trips to a 422.
export const logFiltersSchema = z.object({
  from: z
    .string()
    .trim()
    .refine((v) => v === "" || /^[1-9]\d{0,9}$/.test(v), "logFromInvalid"),
  ip: z
    .string()
    .trim()
    .refine((v) => v === "" || isIpAddress(v), "logIpInvalid"),
  status: z
    .string()
    .trim()
    .refine((v) => v === "" || /^[1-5](\d{2}|xx)$/i.test(v), "logStatusInvalid"),
  method: z.string(),
  url: z.string().trim().max(500, "max500"),
});

export const EMPTY_LOG_FILTERS = { from: "", ip: "", status: "", method: "", url: "" };
