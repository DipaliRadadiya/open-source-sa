import { read } from "@/lib/api/read";
import { ionCubeResponseSchema } from "@/lib/schemas/php";

/**
 * The ionCube Loader's state for one PHP version.
 *
 * Not asked for at all when the version is not `ready`: the endpoint 404s on a
 * version still installing or failed, exactly as the extensions endpoint does,
 * and asking anyway spends a request to be told what the version list already
 * said.
 */
export async function getIonCube(version) {
  if (!version) return { data: null, failed: false };

  const result = await read(`/php/versions/${encodeURIComponent(version)}/ioncube`, ionCubeResponseSchema);

  // Every field `read()` knows, not just whether it worked: without the
  // status and the kind, the failure box on this screen could not tell a
  // 403 from a 500 and printed the same unfalsifiable sentence for both.
  return {
    data: result.failed ? null : (result.data.ioncube ?? null),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
}
