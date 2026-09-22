import { read } from "@/lib/api/read";
import { servicesResponseSchema } from "@/lib/schemas/service";

/**
 * GET /api/services — installed units with their live systemctl status.
 *
 * Returns `{ services, failed }`. A failed request must not degrade to an empty
 * list: "no services installed on this box" is a claim about the machine, and
 * we'd be making it without having heard from the machine.
 */
export async function getServices() {
  const result = await read("/services", servicesResponseSchema);

  // WHICH failure, not just that there was one: without the status and the
  // kind, the error box on this screen printed the same sentence whether the
  // API refused, crashed, or was not there at all.
  return { services: result.failed ? [] : (result.data?.services ?? []), failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}
