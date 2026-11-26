import { read } from "@/lib/api/read";
import { fail2banResponseSchema } from "@/lib/schemas/fail2ban";

export async function getFail2ban() {
  const result = await read("/fail2ban", fail2banResponseSchema);

  return {
    data: result.failed ? null : (result.data.fail2ban ?? null),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
}
