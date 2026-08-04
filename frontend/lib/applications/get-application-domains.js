import { serverFetch } from "@/lib/api/server-fetch";
import { domainsResponseSchema, certificateResponseSchema } from "@/lib/schemas/domain";

async function read(path, schema) {
  try {
    const res = await serverFetch(path);
    if (!res.ok) return { data: null, failed: true };
    const parsed = schema.safeParse(await res.json());
    return parsed.success ? { data: parsed.data, failed: false } : { data: null, failed: true };
  } catch {
    return { data: null, failed: true };
  }
}

export async function getApplicationDomains(id) {
  const result = await read(`/applications/${id}/domains`, domainsResponseSchema);
  return { domains: result.data?.domains ?? [], failed: result.failed };
}

export async function getApplicationCertificate(id) {
  const result = await read(`/applications/${id}/certificate`, certificateResponseSchema);
  // `failed` and "no certificate" are different answers and must stay that way:
  // one means we could not ask, the other means the site is on plain HTTP.
  return { certificate: result.data?.certificate ?? null, failed: result.failed };
}
