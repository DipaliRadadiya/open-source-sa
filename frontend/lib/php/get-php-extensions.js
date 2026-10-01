import { read } from "@/lib/api/read";
import { phpExtensionsResponseSchema } from "@/lib/schemas/php";

// The API returns installed-first, so the order is kept.
export async function getPhpExtensions(version) {
  if (!version) return { data: null, failed: false };

  const result = await read(`/php/versions/${encodeURIComponent(version)}/extensions`, phpExtensionsResponseSchema);

  // Pass through every field `read()` knows so the failure box can tell a 403 from a 500.
  return {
    data: result.failed ? null : (result.data ?? null),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
}
