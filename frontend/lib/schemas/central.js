import { z } from "zod";

/**
 * The central-management connection.
 *
 * - `POST /central/enable` returns the RAW token, the only time it leaves the
 *   database; `GET /central/status` returns only a mask.
 * - Calling enable again ROTATES the token (there is no separate regenerate
 *   endpoint) and the old one stops working.
 * - The token authenticates as a full administrator on every endpoint and
 *   cannot be scoped.
 */

export const centralStatusSchema = z
  .object({
    enabled: z.boolean().default(false),
    // The mask, e.g. "sv_central_a***************". Null while disabled; never
    // the raw value.
    token: z.string().nullish(),
  })
  .passthrough();

export const centralStatusResponseSchema = z
  .object({ central: centralStatusSchema })
  .passthrough();

/**
 * The creation response. `central_token` is the raw secret, sent only here:
 * never log it, persist it, or put it in a URL.
 */
export const centralEnableResponseSchema = z
  .object({
    central_token: z.string(),
    message: z.string().nullish(),
  })
  .passthrough();
