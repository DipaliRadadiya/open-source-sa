import { serverFetch } from "@/lib/api/server-fetch";
import { readErrorBody } from "@/lib/api/error-body";
import { getApplication } from "@/lib/applications/get-applications";

// Anything under a site, not the site itself: `/applications/7/domains`.
const UNDER_APPLICATION = /^\/applications\/(\d+)\/./;

/**
 * Why a read failed:
 *
 * `http` — the API answered with a non-2xx status.
 * `shape` — it answered 200 with a body the schema rejected.
 * `network` — the request never completed.
 */
export const READ_FAILURES = ["http", "shape", "network"];

/**
 * Log the failure to the frontend service's journal. These reads run in Server
 * Components, so they never appear in the browser's network tab. Includes Zod's
 * message for shape failures.
 */
function report(path, failure, detail) {
  console.error(`[read] ${path} failed (${failure})${detail ? `: ${detail}` : ""}`);
}

/**
 * One server-side read: fetch, validate, and report failure honestly.
 *
 * `failed` says whether the answer is usable, `status` is always carried (so a
 * caller can act on 404), and `failure` names the kind (see READ_FAILURES).
 * A schema mismatch counts as `failed`, never as empty data.
 *
 * @returns {Promise<{data: unknown|null, failed: boolean, status: number|null, failure: string|null}>}
 */
export async function read(path, schema, options) {
  /*
   * A site whose system user is missing answers 409 on every route under it.
   * Next still renders the page under the layout's notice, so skip those reads.
   * The site record is already in the request cache from the layout.
   */
  const site = UNDER_APPLICATION.exec(path);
  if (site && (await getApplication(site[1])).application?.system_user === null) {
    return { data: null, failed: true, status: 409, failure: "http", message: null, debug: null };
  }

  try {
    const res = await serverFetch(path, options);
    if (!res.ok) {
      report(path, "http", String(res.status));
      // Carry the API's own message out with the status so the screen can show it.
      const body = await readErrorBody(res);
      return {
        data: null,
        failed: true,
        status: res.status,
        failure: "http",
        message: body.message,
        debug: body.debug,
      };
    }

    const parsed = schema.safeParse(await res.json());
    if (!parsed.success) {
      // First issue only; a mismatch usually repeats across many fields.
      const issue = parsed.error.issues?.[0];
      report(path, "shape", issue ? `${issue.path?.join(".") || "(root)"} — ${issue.message}` : null);
      return { data: null, failed: true, status: res.status, failure: "shape", message: null, debug: false };
    }

    return { data: parsed.data, failed: false, status: res.status, failure: null, message: null, debug: false };
  } catch (error) {
    // Network-level failure: there is no status to report.
    report(path, "network", error?.message);
    return { data: null, failed: true, status: null, failure: "network", message: null, debug: false };
  }
}

/**
 * `read` that degrades to `fallback` on failure, for independent sections that
 * should not blank each other. A separate function so discarding a failure is
 * visible at the call site.
 */
export async function readOr(path, schema, fallback, options) {
  const { data, failed } = await read(path, schema, options);
  return failed ? fallback : data;
}
