import { serverFetch } from "@/lib/api/server-fetch";
import { servicesResponseSchema } from "@/lib/schemas/service";

/**
 * GET /api/services — installed units with their live systemctl status.
 *
 * Returns `{ services, failed }`. A failed request must not degrade to an empty
 * list: "no services installed on this box" is a claim about the machine, and
 * we'd be making it without having heard from the machine.
 */
export async function getServices() {
  try {
    const res = await serverFetch("/services");
    if (!res.ok) return { services: [], failed: true };

    const parsed = servicesResponseSchema.safeParse(await res.json());
    return parsed.success
      ? { services: parsed.data.services, failed: false }
      : { services: [], failed: true };
  } catch {
    return { services: [], failed: true };
  }
}
