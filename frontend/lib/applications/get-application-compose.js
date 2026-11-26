import { read } from "@/lib/api/read";
import { composeFileResponseSchema } from "@/lib/schemas/docker";

// A failed read stays a failure: an empty editor saved back would wipe the site.
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
