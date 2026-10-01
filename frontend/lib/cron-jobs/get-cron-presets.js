import { serverFetch } from "@/lib/api/server-fetch";

// Presets are a convenience; the form works without them, so a failure degrades
// to [] instead of taking the page down.
async function fetchJson(path, pick) {
  try {
    const res = await serverFetch(path);
    if (!res.ok) return null;
    return pick(await res.json());
  } catch {
    return null;
  }
}

export async function getSchedulePresets() {
  const presets = await fetchJson("/cronjobs/schedule-presets", (j) => j?.presets);
  return Array.isArray(presets) ? presets : [];
}

export async function getCommandPresets() {
  const data = await fetchJson("/cronjobs/command-presets", (j) => j);
  return {
    placeholder: data?.placeholder ?? "{path}",
    presets: Array.isArray(data?.presets) ? data.presets : [],
  };
}
