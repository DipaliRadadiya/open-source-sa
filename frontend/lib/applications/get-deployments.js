import { read } from "@/lib/api/read";
import { deploymentsResponseSchema } from "@/lib/schemas/deploy-history";

/**
 * One site's deploy history and its settings.
 *
 * Both come back from the same endpoint, so this is one request rather than
 * two for facts the API already returns together.
 */
export async function getDeployments(applicationId) {
  const result = await read(`/applications/${applicationId}/deployments`, deploymentsResponseSchema);

  return {
    deployments: result.data?.deployments ?? [],
    settings: result.data?.settings ?? null,
    failed: result.failed,
  };
}
