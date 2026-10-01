import { cache } from "react";
import { read } from "@/lib/api/read";
import {
  enginesResponseSchema,
  databasesResponseSchema,
  untrackedResponseSchema,
  connectionsResponseSchema,
} from "@/lib/schemas/database";
import { listQuery, EMPTY_LIST_META } from "@/lib/schemas/list";
import { countByApplication } from "@/lib/backups/database-availability";

/**
 * Shapes are imported, never restated here: an inline copy silently rejects
 * the whole response when the API changes.
 */

export const getEngines = cache(async function getEngines() {
  // `status` and `failure` let callers tell e.g. a 403 apart from an outage.
  const { data, failed, status, failure, message, debug } = await read(
    "/databases/engines",
    enginesResponseSchema,
  );
  return { engines: data?.engines ?? [], failed, status, failure, message, debug };
});

/**
 * One page of the databases list. Search and filters are server-side, since
 * the API pages the results.
 */
export const getDatabases = cache(async function getDatabases(query = "") {
  const { data, failed, status, failure, message, debug } = await read("/databases", databasesResponseSchema, {
    // `attached` must be 0/1: Laravel's `boolean` rule 422s "true"/"false".
    searchParams: listQuery(query, { filters: { engine: "engine", attached: "attached" } }),
  });
  return { databases: data?.databases ?? [], meta: data?.meta ?? EMPTY_LIST_META, failed, status, failure, message, debug };
});

/**
 * How many databases belong to no site, server-wide (not just the current
 * page). Only `meta.total` is used.
 */
export const getUnlinkedCount = cache(async function getUnlinkedCount() {
  const { data, failed } = await read("/databases", databasesResponseSchema, {
    // per_page must be one of 10/20/30/50/100; anything else 422s.
    searchParams: { "filter[attached]": 0, per_page: 10 },
  });

  // A failed count is 0, so no banner rather than a wrong number.
  return failed ? 0 : (data?.meta?.total ?? 0);
});

/**
 * The databases attached to one site. Callers must render `failed` as
 * "could not check", never as "this site has none".
 */
export const getApplicationDatabases = cache(async function getApplicationDatabases(applicationId) {
  const { data, failed } = await read("/databases", databasesResponseSchema, {
    searchParams: { "filter[application_id]": applicationId, per_page: 100 },
  });

  return { databases: data?.databases ?? [], failed };
});

/**
 * Databases that belong to no site: what the "attach a database" picker
 * offers. Databases on another site are excluded so they are never moved silently.
 */
export const getUnattachedDatabases = cache(async function getUnattachedDatabases() {
  const { data, failed } = await read("/databases", databasesResponseSchema, {
    searchParams: { "filter[attached]": 0, per_page: 100 },
  });

  return { databases: data?.databases ?? [], failed };
});

/**
 * How many databases each site has, for the backup form's warning. One page
 * of 100 (the API maximum); with more rows `known` is false and the form
 * shows no warning rather than guessing.
 */
export const getDatabaseCounts = cache(async function getDatabaseCounts() {
  const { data, failed } = await read("/databases", databasesResponseSchema, {
    // Attached only, so unattached databases do not use up the 100-row page.
    // `1`, not "true": Laravel's `boolean` rule 422s the strings "true"/"false".
    searchParams: { "filter[attached]": 1, per_page: 100 },
  });

  const databases = data?.databases ?? [];
  const total = data?.meta?.total ?? databases.length;

  return {
    counts: countByApplication(databases),
    known: !failed && total <= databases.length,
  };
});

/**
 * Databases on the server that the panel doesn't manage. The API scopes this
 * per engine, so each running engine is asked and results merged. Failures
 * are silent on purpose: it only feeds an optional banner.
 */
export const getUntracked = cache(async function getUntracked(engines) {
  const running = (engines ?? []).filter((engine) => engine.running);
  if (running.length === 0) return [];

  const results = await Promise.all(
    running.map(async (engine) => {
      const { data } = await read(
        `/databases/untracked?engine=${encodeURIComponent(engine.engine)}`,
        untrackedResponseSchema,
      );
      return (data?.untracked ?? []).map((name) => ({
        name,
        engine: engine.engine,
      }));
    }),
  );

  return results.flat();
});

/**
 * The admin connection per engine. Needed even when nothing is reachable —
 * that is exactly when someone has to look at these settings.
 */
export const getConnections = cache(async function getConnections() {
  const { data } = await read("/databases/connections", connectionsResponseSchema);
  return data?.connections ?? [];
});
