import { cache } from "react";
import { z } from "zod";
import { serverFetch } from "@/lib/api/server-fetch";
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
  try {
    const res = await serverFetch("/php");
    if (!res.ok) return { data: null, failed: true };

    const parsed = phpResponseSchema.safeParse(await res.json());
    return parsed.success ? { data: parsed.data.php, failed: false } : { data: null, failed: true };
  } catch {
    return { data: null, failed: true };
  }
});
