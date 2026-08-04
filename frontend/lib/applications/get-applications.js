import { cache } from "react";
import { serverFetch } from "@/lib/api/server-fetch";
import {
  applicationResponseSchema,
  applicationsResponseSchema,
  siteTypesResponseSchema,
} from "@/lib/schemas/application";

async function read(path, schema) {
  try {
    const res = await serverFetch(path);
    if (!res.ok) return { data: null, failed: true, status: res.status };
    const parsed = schema.safeParse(await res.json());
    return parsed.success
      ? { data: parsed.data, failed: false, status: res.status }
      : { data: null, failed: true, status: res.status };
  } catch {
    return { data: null, failed: true, status: null };
  }
}

export const getApplications = cache(async function getApplications() {
  const result = await read("/applications", applicationsResponseSchema);
  return { applications: result.data?.applications ?? [], failed: result.failed };
});

export const getSiteTypes = cache(async function getSiteTypes() {
  const result = await read("/site-types", siteTypesResponseSchema);
  return { siteTypes: result.data?.site_types ?? [], failed: result.failed };
});

export async function getApplication(id) {
  const result = await read(`/applications/${id}`, applicationResponseSchema);
  return { application: result.data?.application ?? null, failed: result.failed, status: result.status };
}
