import { z } from "zod";
import { serverFetch } from "@/lib/api/server-fetch";
import { shellSchema } from "@/lib/schemas/system-user";

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
