import { z } from "zod";

/**
 * The central-management connection.
 *
 * The shape is small but the rules behind it decide the whole screen:
 *
 * - `POST /central/enable` returns the RAW token, and that response is the only
 *   place it ever exists outside the database. `GET /central/status` returns a
 *   mask and nothing else, forever.
 * - Calling enable a second time ROTATES: a new token replaces the old one and
 *   the old one stops working on the next request. There is no separate
 *   regenerate endpoint, so "connect" and "regenerate" are the same call with
 *   very different consequences.
 * - The token authenticates as a full administrator (CentralUser is created
 *   with is_admin and the Administrator role), on every endpoint. It cannot be
 *   scoped or made read-only.
 */

export const centralStatusSchema = z
  .object({
    enabled: z.boolean().default(false),
    // The mask, e.g. "sv_central_a***************". Null while disabled.
    // Never the raw value — status has no code path that returns it.
    token: z.string().nullish(),
  })
  .passthrough();

export const centralStatusResponseSchema = z
  .object({ central: centralStatusSchema })
  .passthrough();

/**
 * The creation response. `central_token` here IS the raw secret — the only
 * time it is ever sent — so nothing may log it, persist it, or put it in a URL.
 */
export const centralEnableResponseSchema = z
  .object({
    central_token: z.string(),
    message: z.string().nullish(),
  })
  .passthrough();
