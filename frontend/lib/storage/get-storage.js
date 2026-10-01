import { cache } from "react";
import { read } from "@/lib/api/read";
import { storageDestinationsResponseSchema } from "@/lib/schemas/storage";

// Cached per request: Backups also needs this list for its destination picker.
export const getStorageDestinations = cache(async function getStorageDestinations() {
  const result = await read("/integrations/storage/destinations", storageDestinationsResponseSchema);
  return {
    destinations: result.data?.storage_destinations ?? [],
    oauthRedirectUri: result.data?.google_oauth_redirect_uri ?? null,
    failed: result.failed,
    status: result.status,
    failure: result.failure, message: result.message, debug: result.debug,
  };
});
