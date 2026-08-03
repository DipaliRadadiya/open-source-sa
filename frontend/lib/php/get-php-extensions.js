import { serverFetch } from "@/lib/api/server-fetch";
import { phpExtensionsResponseSchema } from "@/lib/schemas/php";

/**
 * The extension catalog for one PHP version — ~96 rows on a normal server.
 *
 * The API returns them installed-first, so the order is kept as received rather
 * than re-sorted here.
 */
export async function getPhpExtensions(version) {
  if (!version) return { data: null, failed: false };

  try {
    const res = await serverFetch(
      `/php/versions/${encodeURIComponent(version)}/extensions`,
    );
    if (!res.ok) return { data: null, failed: true };

    const parsed = phpExtensionsResponseSchema.safeParse(await res.json());
    return parsed.success ? { data: parsed.data, failed: false } : { data: null, failed: true };
  } catch {
    return { data: null, failed: true };
  }
}
