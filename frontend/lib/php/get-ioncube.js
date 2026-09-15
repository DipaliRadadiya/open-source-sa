import { serverFetch } from "@/lib/api/server-fetch";
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

  try {
    const res = await serverFetch(`/php/versions/${encodeURIComponent(version)}/ioncube`);
    if (!res.ok) return { data: null, failed: true };

    const parsed = ionCubeResponseSchema.safeParse(await res.json());
    return parsed.success ? { data: parsed.data.ioncube, failed: false } : { data: null, failed: true };
  } catch {
    return { data: null, failed: true };
  }
}
