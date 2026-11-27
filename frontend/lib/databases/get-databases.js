import { cache } from "react";
import { read } from "@/lib/api/read";
import {
  enginesResponseSchema,
  databasesResponseSchema,
  untrackedResponseSchema,
  connectionsResponseSchema,
  remoteAccessResponseSchema,
} from "@/lib/schemas/database";
import { rememberedPerPage } from "@/lib/tables/remembered-per-page";
import { listQuery, EMPTY_LIST_META } from "@/lib/schemas/list";
import { countByApplication } from "@/lib/backups/database-availability";

export const getEngines = cache(async function getEngines() {
  const { data, failed, status, failure, message, debug } = await read(
    "/databases/engines",
    enginesResponseSchema,
  );
  return { engines: data?.engines ?? [], failed, status, failure, message, debug };
});

export const getDatabases = cache(async function getDatabases(query = "") {
  const { data, failed, status, failure, message, debug } = await read("/databases", databasesResponseSchema, {
    // Must be 0/1: Laravel's boolean rule rejects "true"/"false".
    searchParams: listQuery(query, { filters: { engine: "engine", attached: "attached" }, defaultPerPage: await rememberedPerPage() }),
  });
  return { databases: data?.databases ?? [], meta: data?.meta ?? EMPTY_LIST_META, failed, status, failure, message, debug };
});

export const getUnlinkedCount = cache(async function getUnlinkedCount() {
  const { data, failed } = await read("/databases", databasesResponseSchema, {
    // per_page must be 10/20/30/50/100, anything else is a 422.
    searchParams: { "filter[attached]": 0, per_page: 10 },
  });

  return failed ? 0 : (data?.meta?.total ?? 0);
});

export const getApplicationDatabases = cache(async function getApplicationDatabases(applicationId) {
  const { data, failed } = await read("/databases", databasesResponseSchema, {
    searchParams: { "filter[application_id]": applicationId, per_page: 100 },
  });

  return { databases: data?.databases ?? [], failed };
});

export const getUnattachedDatabases = cache(async function getUnattachedDatabases() {
  const { data, failed } = await read("/databases", databasesResponseSchema, {
    searchParams: { "filter[attached]": 0, per_page: 100 },
  });

  return { databases: data?.databases ?? [], failed };
});

export const getDatabaseCounts = cache(async function getDatabaseCounts() {
  const { data, failed } = await read("/databases", databasesResponseSchema, {
    // 1, not "true": Laravel's boolean rule rejects the strings.
    searchParams: { "filter[attached]": 1, per_page: 100 },
  });

  const databases = data?.databases ?? [];
  const total = data?.meta?.total ?? databases.length;

  return {
    counts: countByApplication(databases),
    known: !failed && total <= databases.length,
  };
});

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

export const getConnections = cache(async function getConnections() {
  const { data } = await read("/databases/connections", connectionsResponseSchema);
  return data?.connections ?? [];
});

export const REMOTE_ACCESS_ENGINES = ["mysql", "mariadb", "postgresql"];

// A failed read is left out: no note beats a guessed one.
export async function getRemoteAccess(engines = []) {
  const names = engines
    .filter((engine) => engine.running && REMOTE_ACCESS_ENGINES.includes(engine.engine))
    .map((engine) => engine.engine);
  const states = await Promise.all(
    names.map(async (name) => {
      const { data } = await read(
        `/databases/connections/${encodeURIComponent(name)}/remote-access`,
        remoteAccessResponseSchema,
      );
      return data?.remote_access ?? null;
    }),
  );
  return states.filter(Boolean);
}
