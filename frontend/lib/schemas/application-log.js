import { z } from "zod";
import { isIpAddress } from "../validation/ip.js";

export const applicationLogSourceSchema = z
  .object({
    key: z.string(),
    label: z.string(),
    kind: z.string().nullish(),
    exists: z.boolean().default(false),
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
    first_line: z.number().nullish(),
    truncated: z.boolean().default(false),
    search_window_capped: z.boolean().default(false),
  })
  .passthrough();

export const applicationLogResponseSchema = z.object({
  log: applicationLogSchema,
});

export const LOG_METHODS = ["GET", "POST", "PUT", "PATCH", "DELETE", "HEAD", "OPTIONS"];

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
