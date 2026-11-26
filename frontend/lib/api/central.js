import { api } from "@/lib/api/client";

export function enableCentral() {
  return api.post("/central/enable");
}

export function getCentralStatus({ signal } = {}) {
  return api.get("/central/status", { signal });
}

export function disableCentral() {
  return api.delete("/central");
}
