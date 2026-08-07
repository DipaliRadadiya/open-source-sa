import { cache } from "react";
import { read } from "@/lib/api/read";
import { storageDestinationsResponseSchema } from "@/lib/schemas/storage";

/**
 * Connected S3-compatible destinations.
 *
 * Cached per request because the application Backups screen needs the same
 * list to populate its destination picker — asking twice on one render would
 * be two round trips for one answer.
 */
export const getStorageDestinations = cache(async function getStorageDestinations() {
  const result = await read("/integrations/storage/destinations", storageDestinationsResponseSchema);
  return {
    destinations: result.data?.storage_destinations ?? [],
    failed: result.failed,
  };
});
