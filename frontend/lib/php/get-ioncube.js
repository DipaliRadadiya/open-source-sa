import { read } from "@/lib/api/read";
import { ionCubeResponseSchema } from "@/lib/schemas/php";

// Only call when the version is `ready`; the endpoint 404s otherwise.
export async function getIonCube(version) {
  if (!version) return { data: null, failed: false };

  const result = await read(`/php/versions/${encodeURIComponent(version)}/ioncube`, ionCubeResponseSchema);

  // Pass through every field `read()` knows so the failure box can tell a 403 from a 500.
  return {
    data: result.failed ? null : (result.data.ioncube ?? null),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
}
