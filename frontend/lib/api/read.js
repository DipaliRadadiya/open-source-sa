import { serverFetch } from "@/lib/api/server-fetch";

/**
 * One server-side read: fetch, validate, and report failure honestly.
 *
 * Five fetcher files had each grown their own copy of this, and they had
 * drifted into three different contracts — two byte-identical, three dropping
 * `status` (so their callers could not tell a 404 from a 500, and could not
 * call `notFound()`), and one swallowing failure into a fallback value so a
 * dead API rendered as data.
 *
 * The contract here is deliberately the widest of those: `failed` says whether
 * we got a usable answer, and `status` is always carried so a caller that
 * cares about 404 can act on it. A caller that only checks `failed` is
 * unaffected by the extra key.
 *
 * A schema that fails to parse counts as `failed`, not as empty data — the
 * response arrived but is not the shape this screen was written against, and
 * rendering it as "nothing here" is the same lie as rendering a 500 that way.
 *
 * @returns {Promise<{data: unknown|null, failed: boolean, status: number|null}>}
 */
export async function read(path, schema, options) {
  try {
    const res = await serverFetch(path, options);
    if (!res.ok) return { data: null, failed: true, status: res.status };

    const parsed = schema.safeParse(await res.json());
    return parsed.success
      ? { data: parsed.data, failed: false, status: res.status }
      : { data: null, failed: true, status: res.status };
  } catch {
    // Network-level failure: there is no status to report.
    return { data: null, failed: true, status: null };
  }
}

/**
 * `read` for the callers that genuinely want to degrade rather than fail —
 * the database monitor asks four independent questions and one engine having
 * no history should not blank the other three.
 *
 * Kept as its own named function rather than an option on `read`, so the
 * decision to discard a failure is visible at the call site instead of hiding
 * in an argument.
 */
export async function readOr(path, schema, fallback, options) {
  const { data, failed } = await read(path, schema, options);
  return failed ? fallback : data;
}
