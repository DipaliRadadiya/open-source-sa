import { read } from "@/lib/api/read";
import { deploymentsResponseSchema, latestDeploymentResponseSchema } from "@/lib/schemas/deploy-history";

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

export async function getLatestDeployment(applicationId) {
  const result = await read(`/applications/${applicationId}/deployments/latest`, latestDeploymentResponseSchema);
  return { latest: result.data?.latest ?? null, failed: result.failed };
}
