import { cache } from "react";
import { z } from "zod";
import { read } from "@/lib/api/read";
import { phpGroupSchema } from "@/lib/schemas/php";

// One definition of the shape. This restated it inline, so when the API changed
// the form of `installable` the schema file was fixed while this copy kept
// rejecting every response — and the whole page read "we couldn't load PHP".
const phpResponseSchema = z.object({ php: phpGroupSchema });

/**
 * Everything the PHP screen needs, in one call.
 *
 * PHP used to be a group inside `GET /settings` and a second endpoint under the
 * `service` permission — so managing a PHP version required the `setting`
 * permission, which also grants the SSH port and the reboot button. It is one
 * feature behind one `php` permission now.
 */
export const getPhp = cache(async function getPhp() {
  const result = await read("/php", phpResponseSchema);

  // Every field `read()` knows, not just whether it worked: without the
  // status and the kind, the failure box on this screen could not tell a
  // 403 from a 500 and printed the same unfalsifiable sentence for both.
  return {
    data: result.failed ? null : (result.data.php ?? null),
    failed: result.failed,
    status: result.status,
    failure: result.failure,
    message: result.message,
    debug: result.debug,
  };
});
