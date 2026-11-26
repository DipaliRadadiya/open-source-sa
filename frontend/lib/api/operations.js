import { api } from "@/lib/api/client";

export function listOperations({ active = false, finishedAfter = null, signal } = {}) {
  const params = active ? { active: 1, ...(finishedAfter ? { finished_after: finishedAfter } : null) } : undefined;
  return api.get("/operations", { params, signal });
}

export function apiStampUtc(date) {
  const pad = (n) => String(n).padStart(2, "0");
  return `${pad(date.getUTCDate())}-${pad(date.getUTCMonth() + 1)}-${date.getUTCFullYear()} ${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}:${pad(date.getUTCSeconds())}`;
}
