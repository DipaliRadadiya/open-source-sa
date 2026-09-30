import { serverFetch } from "@/lib/api/server-fetch";
import { readErrorBody } from "@/lib/api/error-body";
import { getApplication } from "@/lib/applications/get-applications";

// Anything under a site, not the site itself: `/applications/7/domains`.
const UNDER_APPLICATION = /^\/applications\/(\d+)\/./;

/**
 * Why a read failed, in one word.
 *
 * `http` — the API answered, with a status that is not 2xx.
 * `shape` — it answered 200 with something this screen was not written against.
 * `network` — the request never completed.
 *
 * Three genuinely different problems with three different fixes, and the panel
 * used to collapse them into one boolean: the red box said "could not be
 * loaded" and nothing anywhere — screen, log, or devtools — could say which had
 * happened. A whole morning went into guessing at one of these.
 */
export const READ_FAILURES = ["http", "shape", "network"];

/**
 * Say what went wrong, where someone can find it.
 *
 * Server-side `console.error`, so it lands in the frontend service's journal.
 * These reads run in Server Components, so the request never appears in the
 * browser's network tab — without this line there is nowhere at all to look.
 *
 * Zod's own message is included for a shape failure: "expected number, received
 * string at meta.total" is the entire diagnosis, and it is not guessable from
 * the outside.
 */
function report(path, failure, detail) {
  console.error(`[read] ${path} failed (${failure})${detail ? `: ${detail}` : ""}`);
}

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
 * we got a usable answer, `status` is always carried so a caller that cares
 * about 404 can act on it, and `failure` names which kind of wrong it was so
 * the screen can stop making the user guess. A caller that only checks `failed`
 * is unaffected by the extra keys.
 *
 * A schema that fails to parse counts as `failed`, not as empty data — the
 * response arrived but is not the shape this screen was written against, and
 * rendering it as "nothing here" is the same lie as rendering a 500 that way.
 *
 * @returns {Promise<{data: unknown|null, failed: boolean, status: number|null, failure: string|null}>}
 */
export async function read(path, schema, options) {
  /*
   * A site whose system user is missing answers 409 on every route under it.
   * Its layout shows one panel instead of the page, but Next renders the page
   * alongside it anyway, so each of its reads still went out to be refused.
   * The site's own record is already in the request cache from the layout.
   */
  const site = UNDER_APPLICATION.exec(path);
  if (site && (await getApplication(site[1])).application?.system_user === null) {
    return { data: null, failed: true, status: 409, failure: "http", message: null, debug: null };
  }

  try {
    const res = await serverFetch(path, options);
    if (!res.ok) {
      report(path, "http", String(res.status));
      /*
       * The API's own sentence, carried out with the status.
       *
       * It was read, logged and dropped, so every failed section rendered our
       * category — "The server had a problem" — while the server had said
       * "The application list could not be read from disk." Krishna, this
       * morning, about the whole-page version of the same thing: "why we
       * cannot see actual message instead of showing just Your server
       * returned an error".
       */
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
      // The first issue only: a mismatched response usually fails the same way
      // in fifty places, and fifty lines of it buries the one that matters.
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
