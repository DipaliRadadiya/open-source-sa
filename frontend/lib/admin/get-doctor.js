import { cache } from "react";
import { z } from "zod";
import { read } from "@/lib/api/read";
import { doctorSchema } from "@/lib/schemas/doctor";

// Admin-only on the backend, so only call it for an admin.
export const getDoctor = cache(async function getDoctor() {
  const { data, failed, status, failure, message, debug } = await read(
    "/admin/doctor",
    z.object({ doctor: doctorSchema }),
  );

  return { doctor: data?.doctor ?? null, failed, status, failure, message, debug };
});
