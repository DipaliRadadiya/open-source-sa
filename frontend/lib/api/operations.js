import { api } from "@/lib/api/client";

// Polled: the endpoint sits outside the global limiter (`throttle:progress`).
// `active` returns only queued and running work, which is what the badge needs.
// `finishedAfter` (d-m-Y H:i:s, with `active`): also the jobs that ended since then,
// so one that starts and finishes between two polls is still reported.
export function listOperations({ active = false, finishedAfter = null, signal } = {}) {
  const params = active ? { active: 1, ...(finishedAfter ? { finished_after: finishedAfter } : null) } : undefined;
  return api.get("/operations", { params, signal });
}

// The API's clock is UTC and its dates are d-m-Y H:i:s.
export function apiStampUtc(date) {
  const pad = (n) => String(n).padStart(2, "0");
  return `${pad(date.getUTCDate())}-${pad(date.getUTCMonth() + 1)}-${date.getUTCFullYear()} ${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}:${pad(date.getUTCSeconds())}`;
}
