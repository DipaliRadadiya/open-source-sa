import { read } from "@/lib/api/read";
import { phpExtensionsResponseSchema } from "@/lib/schemas/php";

export async function getPhpExtensions(version) {
  if (!version) return { data: null, failed: false };

  const result = await read(`/php/versions/${encodeURIComponent(version)}/extensions`, phpExtensionsResponseSchema);

  return {
    data: result.failed ? null : (result.data ?? null),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
}
