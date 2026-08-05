import { serverFetch } from "@/lib/api/server-fetch";
import { environmentResponseSchema } from "@/lib/schemas/environment";

export async function getApplicationEnvironment(id) {
  try {
    const res = await serverFetch(`/applications/${id}/environment`);
    if (!res.ok) return { environment: null, failed: true };
    const parsed = environmentResponseSchema.safeParse(await res.json());
    return parsed.success
      ? { environment: parsed.data.environment, failed: false }
      : { environment: null, failed: true };
  } catch {
    return { environment: null, failed: true };
  }
}
