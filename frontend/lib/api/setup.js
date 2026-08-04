import { api } from "@/lib/api/client";
import { setupResponseSchema } from "@/lib/schemas/setup";

// The axios client already bakes in the `/api` prefix, so strip it from the
// endpoint the API hands back (`/api/databases/engines/mariadb`).
function toClientPath(endpoint) {
  return endpoint.replace(/^\/api/, "");
}

/**
 * Runs a component/option install action. `action` is the `{method, endpoint}`
 * object straight from the setup payload — the same endpoints PHP/Node/database
 * already use, so there is no second install path to drift.
 */
export function runSetupAction(action, body) {
  return api.request({
    method: action.method ?? "POST",
    url: toClientPath(action.endpoint),
    ...(body ? { data: body } : {}),
  });
}

/**
 * Client-side poll of the setup state while something is installing.
 */
export async function fetchSetup() {
  const { data } = await api.get("/setup");
  const parsed = setupResponseSchema.safeParse(data);
  return parsed.success ? parsed.data.setup : null;
}
