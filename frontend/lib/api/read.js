import { serverFetch } from "@/lib/api/server-fetch";
import { readErrorBody } from "@/lib/api/error-body";
import { getApplication } from "@/lib/applications/get-applications";

const UNDER_APPLICATION = /^\/applications\/(\d+)\/./;

function report(path, failure, detail) {
  console.error(`[read] ${path} failed (${failure})${detail ? `: ${detail}` : ""}`);
}

// A schema mismatch counts as failed, not as empty data.
export async function read(path, schema, options) {
  const site = UNDER_APPLICATION.exec(path);
  if (site && (await getApplication(site[1])).application?.system_user === null) {
    return { data: null, failed: true, status: 409, failure: "http", message: null, debug: null };
  }

  try {
    const res = await serverFetch(path, options);
    if (!res.ok) {
      report(path, "http", String(res.status));
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
      const issue = parsed.error.issues?.[0];
      report(path, "shape", issue ? `${issue.path?.join(".") || "(root)"} — ${issue.message}` : null);
      return { data: null, failed: true, status: res.status, failure: "shape", message: null, debug: false };
    }

    return { data: parsed.data, failed: false, status: res.status, failure: null, message: null, debug: false };
  } catch (error) {
    report(path, "network", error?.message);
    return { data: null, failed: true, status: null, failure: "network", message: null, debug: false };
  }
}

export async function readOr(path, schema, fallback, options) {
  const { data, failed } = await read(path, schema, options);
  return failed ? fallback : data;
}
