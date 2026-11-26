import { cache } from "react";
import { read } from "@/lib/api/read";
import { settingsResponseSchema } from "@/lib/schemas/settings";

export const getSettings = cache(async function getSettings() {
  const result = await read("/settings", settingsResponseSchema);

  if (result.failed) {
    return {
      data: null,
      lastChanged: null,
      failed: true,
      status: result.status,
      failure: result.failure,
      message: result.message,
      debug: result.debug,
    };
  }

  return {
    data: result.data.settings,
    lastChanged: result.data.last_changed ?? null,
    failed: false,
    status: result.status,
    failure: null,
    message: null,
    debug: false,
  };
});

