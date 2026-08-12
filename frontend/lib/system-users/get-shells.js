import { z } from "zod";
import { serverFetch } from "@/lib/api/server-fetch";
import { shellSchema } from "@/lib/schemas/system-user";

/**
 * The login shells this server will accept, with titles already localised.
 *
 * Fetched rather than hardcoded: the list is the server's to decide, and the
 * titles are what make `/usr/sbin/nologin` legible as "No login". An empty
 * result leaves the picker showing the shell the user already has rather than
 * an empty menu — losing the catalog must not look like losing the setting.
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
