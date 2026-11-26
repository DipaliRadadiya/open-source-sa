import { read } from "@/lib/api/read";
import { composeFileResponseSchema } from "@/lib/schemas/docker";

// The compose file this site runs, read on the server. A failed read stays a failure, never
// "": an empty editor saved back would wipe the site's definition.
export async function getApplicationCompose(id) {
  const result = await read(
    `/applications/${id}/container/compose`,
    composeFileResponseSchema,
  );

  return {
    compose: result.data?.compose ?? "",
    generated: result.data?.generated ?? false,
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
  };
}
