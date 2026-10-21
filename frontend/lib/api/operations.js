import { api } from "@/lib/api/client";

// Polled: the endpoint sits outside the global limiter (`throttle:progress`).
// `active` returns only queued and running work, which is what the badge needs.
export function listOperations({ active = false, signal } = {}) {
  return api.get("/operations", { params: active ? { active: 1 } : undefined, signal });
}
