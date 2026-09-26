import { read } from "@/lib/api/read";
import { deploymentsResponseSchema, latestDeploymentResponseSchema } from "@/lib/schemas/deploy-history";

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
    status: result.status,
    failure: result.failure, message: result.message, debug: result.debug,
  };
}

/** The newest deploy, to tell whether one is running right now. */
export async function getLatestDeployment(applicationId) {
  const result = await read(`/applications/${applicationId}/deployments/latest`, latestDeploymentResponseSchema);
  return { latest: result.data?.latest ?? null, failed: result.failed };
}
