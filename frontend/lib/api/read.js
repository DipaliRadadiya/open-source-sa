import { serverFetch } from "@/lib/api/server-fetch";
import { readErrorBody } from "@/lib/api/error-body";
import { getApplication } from "@/lib/applications/get-applications";

// Anything under a site, not the site itself: `/applications/7/domains`.
const UNDER_APPLICATION = /^\/applications\/(\d+)\/./;

// `http`: non-2xx. `shape`: 200 the schema rejected. `network`: never completed.
export const READ_FAILURES = ["http", "shape", "network"];

// Server Component reads never appear in the browser's network tab.
function report(path, failure, detail) {
  console.error(`[read] ${path} failed (${failure})${detail ? `: ${detail}` : ""}`);
}

// Resolves `{ data, failed, status, failure }`; `status` is always carried.
// A schema mismatch counts as `failed`, never as empty data.
export async function read(path, schema, options) {
  /* A site with no system user answers 409 on every route under it; the layout shows a notice. */
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

// For independent sections that should not blank each other; separate so a discarded failure is visible.
export async function readOr(path, schema, fallback, options) {
  const { data, failed } = await read(path, schema, options);
  return failed ? fallback : data;
}
