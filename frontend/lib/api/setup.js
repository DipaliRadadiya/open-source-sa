import { api } from "@/lib/api/client";
import { setupResponseSchema } from "@/lib/schemas/setup";
import { RETRY_RESTART } from "@/lib/api/restart-blip";

function toClientPath(endpoint) {
  return endpoint.replace(/^\/api/, "");
}

export function runSetupAction(action, body) {
  return api.request({
    method: action.method ?? "POST",
    url: toClientPath(action.endpoint),
    ...(body ? { data: body } : {}),
    ...RETRY_RESTART,
  });
}

export async function fetchSetup() {
  const { data } = await api.get("/setup");
  const parsed = setupResponseSchema.safeParse(data);
  return parsed.success ? parsed.data.setup : null;
}
