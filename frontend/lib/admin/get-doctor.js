import { cache } from "react";
import { z } from "zod";
import { read } from "@/lib/api/read";
import { doctorSchema } from "@/lib/schemas/doctor";

/**
 * Installation self-check (GET /admin/doctor). Admin-only on the backend, so
 * only call it for an admin. A "Re-check" is a full router.refresh(), which
 * re-invokes this.
 *
 * Through `read()` rather than its own try/catch, which is what it was: it
 * returned a bare `null` for a 403, a 500, a dead request and a shape mismatch
 * alike, so the page could say nothing more than "could not be loaded" — and
 * nothing was written to the journal either, because `report()` never ran.
 * Four different problems with four different fixes, collapsed into one word.
 */
export const getDoctor = cache(async function getDoctor() {
  const { data, failed, status, failure } = await read(
    "/admin/doctor",
    z.object({ doctor: doctorSchema }),
  );

  return { doctor: data?.doctor ?? null, failed, status, failure };
});
