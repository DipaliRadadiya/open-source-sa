/**
 * File list preferences (hidden files, sort order), remembered across folders
 * and reloads. A cookie, not localStorage, because the hidden flag filters on
 * the server; an explicit `?hidden=` in the URL still wins.
 */
export const HIDDEN_COOKIE = "sv_files_hidden";
export const SORT_COOKIE = "sv_files_sort";

const SORT_COLUMNS = ["name", "size", "modified"];
const YEAR = 60 * 60 * 24 * 365;

export function resolveShowHidden(param, cookie) {
  if (param === "0") return false;
  if (param === "1") return true;
  return cookie !== "hide";
}

// "size:desc" → [{ id: "size", desc: true }]; anything else → name ascending.
export function parseSort(value) {
  const [id, dir] = typeof value === "string" ? value.split(":") : [];
  return SORT_COLUMNS.includes(id) ? [{ id, desc: dir === "desc" }] : [{ id: "name", desc: false }];
}

export function serializeSort(sorting) {
  const first = sorting?.[0];
  return first && SORT_COLUMNS.includes(first.id) ? `${first.id}:${first.desc ? "desc" : "asc"}` : null;
}

export function writePref(name, value) {
  try {
    document.cookie = value
      ? `${name}=${value}; path=/; max-age=${YEAR}; samesite=lax`
      : `${name}=; path=/; max-age=0; samesite=lax`;
  } catch {
    // A blocked cookie only loses the preference.
  }
}
