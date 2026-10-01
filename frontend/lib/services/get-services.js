import { read } from "@/lib/api/read";
import { servicesResponseSchema } from "@/lib/schemas/service";

/**
 * GET /api/services: installed units with their live systemctl status.
 * A failed request must not read as "no services installed".
 */
export async function getServices() {
  const result = await read("/services", servicesResponseSchema);

  // Pass the status and failure kind so the error box can tell them apart.
  return { services: result.failed ? [] : (result.data?.services ?? []), failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}
