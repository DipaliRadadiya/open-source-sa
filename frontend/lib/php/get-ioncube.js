import { read } from "@/lib/api/read";
import { ionCubeResponseSchema } from "@/lib/schemas/php";

export async function getIonCube(version) {
  if (!version) return { data: null, failed: false };

  const result = await read(`/php/versions/${encodeURIComponent(version)}/ioncube`, ionCubeResponseSchema);

  return {
    data: result.failed ? null : (result.data.ioncube ?? null),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
}
