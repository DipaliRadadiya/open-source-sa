import { read } from "@/lib/api/read";
import { fail2banResponseSchema } from "@/lib/schemas/fail2ban";

/**
 * GET /api/fail2ban — the whole screen in one call.
 *
 * `installed: false` is a valid answer (install prompt); only a real failure
 * sets `failed`, so "couldn't ask" never reads as "no protection".
 */
export async function getFail2ban() {
  const result = await read("/fail2ban", fail2banResponseSchema);

  // Pass through every failure detail so the error box can explain it.
  return {
    data: result.failed ? null : (result.data.fail2ban ?? null),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
}
