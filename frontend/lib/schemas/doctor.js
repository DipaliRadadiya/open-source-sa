import { z } from "zod";

// One installation self-check. `title` and `fix` are localized by the backend
// (we forward Accept-Language); `detail` is raw evidence for an operator (a
// version, a path, a unit name) and is deliberately NOT translated. `fix` is
// null when the check passed.
const checkSchema = z
  .object({
    key: z.string(),
    title: z.string(),
    status: z.enum(["pass", "warn", "fail"]).catch("warn"),
    detail: z.string().nullish(),
    fix: z.string().nullish(),
  })
  .passthrough();

// `healthy` is false only when something FAILED — warnings never make it false.
export const doctorSchema = z
  .object({
    healthy: z.boolean().default(false),
    passed: z.number().int().nonnegative().catch(0),
    failed: z.number().int().nonnegative().catch(0),
    warnings: z.number().int().nonnegative().catch(0),
    checks: z.array(checkSchema).default([]),
  })
  .passthrough();
