import { read } from "@/lib/api/read";
import { setupResponseSchema } from "@/lib/schemas/setup";

export async function getSetup() {
  const { data, failed, status, failure, message, debug } = await read("/setup", setupResponseSchema);

  return { setup: data?.setup ?? null, failed, status, failure, message, debug };
}
