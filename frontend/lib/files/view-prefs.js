/**
 * How the reader likes the file list shown — hidden files and sort order —
 * remembered across folders and reloads.
 *
 * Both lived only in the moment: the sort reset on every reload, and "hide
 * hidden files" reset the moment you opened another folder, because the flag
 * sat in one URL and six different places build folder links without it. A
 * cookie rather than localStorage because the hidden flag filters on the
 * server; an explicit `?hidden=` in the URL still wins, so a shared link shows
 * what its sender saw.
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
    // A blocked cookie costs the preference, nothing else.
  }
}
