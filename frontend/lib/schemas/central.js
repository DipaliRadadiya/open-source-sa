import { z } from "zod";

// The token is a full admin credential. Calling enable again rotates it.

export const centralStatusSchema = z
  .object({
    enabled: z.boolean().default(false),
    token: z.string().nullish(),
    connected: z.boolean().default(false),
    last_used_at: z.string().nullish(),
  })
  .passthrough();

export const centralStatusResponseSchema = z
  .object({ central: centralStatusSchema })
  .passthrough();

// Raw secret: never log it, store it or put it in a URL.
export const centralEnableResponseSchema = z
  .object({
    central_token: z.string(),
    message: z.string().nullish(),
  })
  .passthrough();
