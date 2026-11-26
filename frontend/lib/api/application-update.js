import { api } from "@/lib/api/client";
import { parsedOrThrow } from "@/lib/api/parse-response";
import {
  applicationUpdateResponseSchema,
  startApplicationUpdateResponseSchema,
} from "@/lib/schemas/application-update";

export async function fetchApplicationUpdate(id) {
  const res = await api.get(`/applications/${id}/update`);
  return parsedOrThrow(applicationUpdateResponseSchema, res.data, "fetchApplicationUpdate");
}

export async function startApplicationUpdate(id) {
  const res = await api.post(`/applications/${id}/update`);
  return parsedOrThrow(startApplicationUpdateResponseSchema, res.data, "startApplicationUpdate").update;
}
