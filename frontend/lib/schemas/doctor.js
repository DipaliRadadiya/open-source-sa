import { z } from "zod";

const checkSchema = z
  .object({
    key: z.string(),
    title: z.string(),
    status: z.enum(["pass", "warn", "fail"]).catch("warn"),
    detail: z.string().nullish(),
    fix: z.string().nullish(),
  })
  .passthrough();

export const doctorSchema = z
  .object({
    healthy: z.boolean().default(false),
    passed: z.number().int().nonnegative().catch(0),
    failed: z.number().int().nonnegative().catch(0),
    warnings: z.number().int().nonnegative().catch(0),
    checks: z.array(checkSchema).default([]),
  })
  .passthrough();
