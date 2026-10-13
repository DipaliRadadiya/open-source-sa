import { api } from "@/lib/api/client";
import { parsedOrThrow } from "@/lib/api/parse-response";
import {
  applicationUpdateResponseSchema,
  startApplicationUpdateResponseSchema,
} from "@/lib/schemas/application-update";

// The poll while an update runs (`throttle:progress`); may also look up releases.
export async function fetchApplicationUpdate(id) {
  const res = await api.get(`/applications/${id}/update`);
  return parsedOrThrow(applicationUpdateResponseSchema, res.data, "fetchApplicationUpdate");
}

// No body: the target is `latest_version`. 202 → poll; 409 already running; 422 blocked.
export async function startApplicationUpdate(id) {
  const res = await api.post(`/applications/${id}/update`);
  return parsedOrThrow(startApplicationUpdateResponseSchema, res.data, "startApplicationUpdate").update;
}
