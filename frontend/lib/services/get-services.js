import { read } from "@/lib/api/read";
import { servicesResponseSchema } from "@/lib/schemas/service";

export async function getServices() {
  const result = await read("/services", servicesResponseSchema);

  return { services: result.failed ? [] : (result.data?.services ?? []), failed: result.failed, status: result.status, failure: result.failure, message: result.message, debug: result.debug };
}
