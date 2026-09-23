import { read } from "@/lib/api/read";
import { fail2banResponseSchema } from "@/lib/schemas/fail2ban";

/**
 * GET /api/fail2ban — the whole screen in one call.
 *
 * Returns `{ data, failed }`. `installed: false` is a legitimate answer and
 * renders the install prompt; only a genuine failure sets `failed`, because
 * "we couldn't ask" must never be drawn as "your server has no protection".
 */
export async function getFail2ban() {
  const result = await read("/fail2ban", fail2banResponseSchema);

  // Every field `read()` knows, not just whether it worked: without the
  // status and the kind, the failure box on this screen could not tell a
  // 403 from a 500 and printed the same unfalsifiable sentence for both.
  return {
    data: result.failed ? null : (result.data.fail2ban ?? null),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
}
