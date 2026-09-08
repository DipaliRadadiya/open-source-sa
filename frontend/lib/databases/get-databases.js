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
 * Shapes are imported, never restated here — an inline copy silently rejects
 * the whole response the first time the API grows a field.
 */

export const getEngines = cache(async function getEngines() {
  const { data, failed } = await read("/databases/engines", enginesResponseSchema);
  return { engines: data?.engines ?? [], failed };
});

/**
 * One page of the databases list. Search and the engine filter are the API's:
 * it pages at ten, so a browser-side filter would only ever search the page we
 * happen to hold.
 */
export const getDatabases = cache(async function getDatabases(query = "") {
  const { data, failed, status, failure } = await read("/databases", databasesResponseSchema, {
    // `attached` rides the same map: "false" reaches the API as
    // `filter[attached]=false`, which Laravel's boolean() reads correctly.
    searchParams: listQuery(query, { filters: { engine: "engine", attached: "attached" } }),
  });
  return { databases: data?.databases ?? [], meta: data?.meta ?? EMPTY_LIST_META, failed, status, failure };
});

/**
 * How many databases belong to no site at all.
 *
 * Its own request rather than a count of the page on screen: the list is paged
 * and searchable, so "3 on this page" is not "3 on this server" — and this
 * number is the whole point of the banner it feeds.
 *
 * `per_page: 1` because only `meta.total` is wanted; the row comes back to
 * satisfy the shape and is thrown away.
 */
export const getUnlinkedCount = cache(async function getUnlinkedCount() {
  const { data, failed } = await read("/databases", databasesResponseSchema, {
    searchParams: { "filter[attached]": "false", per_page: 1 },
  });

  // A failed count is 0, which renders nothing. Better a missing banner than
  // one that announces a number it could not read.
  return failed ? 0 : (data?.meta?.total ?? 0);
});

/**
 * The databases attached to one site.
 *
 * `filter[application_id]` is the API's own filter, so this asks for the two
 * rows it wants rather than reading a page of every database on the server and
 * discarding it. A failure returns `failed`, which the caller renders as "could
 * not check" — never as "this site has none", which is the answer that would
 * send someone looking for a problem they do not have.
 */
export const getApplicationDatabases = cache(async function getApplicationDatabases(applicationId) {
  const { data, failed } = await read("/databases", databasesResponseSchema, {
    searchParams: { "filter[application_id]": applicationId, per_page: 100 },
  });

  return { databases: data?.databases ?? [], failed };
});

/**
 * How many databases each site has, for the backup form's warning.
 *
 * One page of 100 — the largest the API allows — rather than walking every
 * page: this powers a warning, and a warning is not worth N requests on the
 * way to a form. When there are more rows than that, `known` goes false and
 * the form says nothing at all rather than guessing about the sites it
 * could not see.
 */
export const getDatabaseCounts = cache(async function getDatabaseCounts() {
  const { data, failed } = await read("/databases", databasesResponseSchema, {
    // Attached only. An unattached database can never contribute to a count,
    // so letting them fill the page budget is how a server with many orphans
    // tipped past 100, answered `known: false`, and quietly switched off every
    // warning in this feature — on exactly the server that needed them most.
    searchParams: { "filter[attached]": "true", per_page: 100 },
  });

  const databases = data?.databases ?? [];
  const total = data?.meta?.total ?? databases.length;

  return {
    counts: countByApplication(databases),
    known: !failed && total <= databases.length,
  };
});

/**
 * Databases sitting on the server that the panel doesn't manage.
 *
 * Scoped per engine by the API, so this asks each running one and merges. A
 * failure here is silent on purpose: it powers a "you may want to adopt these"
 * banner, and a banner that can't load is not worth an error on a page that
 * otherwise works.
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
