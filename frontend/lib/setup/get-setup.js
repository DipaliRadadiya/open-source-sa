import { read } from "@/lib/api/read";
import { setupResponseSchema } from "@/lib/schemas/setup";

// `read()` keeps 403, 500, dead request and shape mismatch distinct.
export async function getSetup() {
  const { data, failed, status, failure, message, debug } = await read("/setup", setupResponseSchema);

  return { setup: data?.setup ?? null, failed, status, failure, message, debug };
}
