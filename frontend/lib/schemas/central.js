import { z } from "zod";

// SECURITY: the token is a full, unscoped administrator. Only enable returns it raw,
// and calling enable again ROTATES it.

export const centralStatusSchema = z
  .object({
    enabled: z.boolean().default(false),
    // The mask, e.g. "sv_central_a***************". Null while disabled; never
    // the raw value.
    token: z.string().nullish(),
    // `enabled` = a key exists; `connected` = Central has used it (ISO `last_used_at`).
    connected: z.boolean().default(false),
    last_used_at: z.string().nullish(),
  })
  .passthrough();

export const centralStatusResponseSchema = z
  .object({ central: centralStatusSchema })
  .passthrough();

// `central_token` is the raw secret: never log it, persist it, or put it in a URL.
export const centralEnableResponseSchema = z
  .object({
    central_token: z.string(),
    message: z.string().nullish(),
  })
  .passthrough();
