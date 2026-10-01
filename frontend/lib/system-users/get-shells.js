import { z } from "zod";
import { serverFetch } from "@/lib/api/server-fetch";
import { shellSchema } from "@/lib/schemas/system-user";

/**
 * The login shells this server accepts, with localised titles (e.g.
 * `/usr/sbin/nologin` as "No login"). Fetched because the list is the server's
 * to decide. An empty result leaves the picker on the current shell, so losing
 * the catalog does not look like losing the setting.
 */
export async function getShells() {
  try {
    const res = await serverFetch("/system-users/shells");
    if (!res.ok) return [];
    const parsed = z
      .array(shellSchema)
      .safeParse((await res.json())?.shells);
    return parsed.success ? parsed.data : [];
  } catch {
    return [];
  }
}
