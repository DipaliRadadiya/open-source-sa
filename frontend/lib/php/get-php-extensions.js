import { read } from "@/lib/api/read";
import { phpExtensionsResponseSchema } from "@/lib/schemas/php";

/**
 * The extension catalog for one PHP version — ~96 rows on a normal server.
 *
 * The API returns them installed-first, so the order is kept as received rather
 * than re-sorted here.
 */
export async function getPhpExtensions(version) {
  if (!version) return { data: null, failed: false };

  const result = await read(`/php/versions/${encodeURIComponent(version)}/extensions`, phpExtensionsResponseSchema);

  // Every field `read()` knows, not just whether it worked: without the
  // status and the kind, the failure box on this screen could not tell a
  // 403 from a 500 and printed the same unfalsifiable sentence for both.
  return {
    data: result.failed ? null : (result.data ?? null),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
}
