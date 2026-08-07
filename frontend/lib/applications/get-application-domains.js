import { domainsResponseSchema, certificateResponseSchema } from "@/lib/schemas/domain";
import { read } from "@/lib/api/read";


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
