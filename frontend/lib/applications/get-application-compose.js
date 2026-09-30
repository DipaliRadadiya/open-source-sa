import { read } from "@/lib/api/read";
import { composeFileResponseSchema } from "@/lib/schemas/docker";

/**
 * The compose file this site runs.
 *
 * Read on the server so the page arrives with the file in it. The dialog this
 * replaced fetched on open, which meant a spinner every time somebody wanted to
 * look at their own configuration.
 *
 * Does NOT degrade to an empty string on failure. An empty editor is a claim that
 * the file is empty, and saving that claim back would replace a working site's
 * definition with nothing — so a failed read renders as a failure.
 */
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
