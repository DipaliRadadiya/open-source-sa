import { serverFetch } from "@/lib/api/server-fetch";
import { fail2banResponseSchema } from "@/lib/schemas/fail2ban";

/**
 * GET /api/fail2ban — the whole screen in one call.
 *
 * Returns `{ data, failed }`. `installed: false` is a legitimate answer and
 * renders the install prompt; only a genuine failure sets `failed`, because
 * "we couldn't ask" must never be drawn as "your server has no protection".
 */
export async function getFail2ban() {
  try {
    const res = await serverFetch("/fail2ban");
    if (!res.ok) return { data: null, failed: true };

    const parsed = fail2banResponseSchema.safeParse(await res.json());
    return parsed.success
      ? { data: parsed.data.fail2ban, failed: false }
      : { data: null, failed: true };
  } catch {
    return { data: null, failed: true };
  }
}
