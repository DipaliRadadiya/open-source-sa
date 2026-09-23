/*
 * One-click jumps to the places people actually go in an app they know by
 * name, not by path. "Where are my uploads?" is the question; `wp-content/
 * uploads` is the answer only someone who already knows it can type.
 *
 * Only for types whose layout is confirmed, not remembered. WordPress was
 * read off a live listing: the root holds `wp-config.php` and `wp-content`,
 * and `wp-content` holds `uploads`, `themes` and `plugins`. Add a type here
 * the same way — from a real listing of an installed one — or a chip will
 * open "This folder is gone" on the first click.
 */
const SHORTCUTS = {
  wordpress: [
    { key: "uploads", path: "wp-content/uploads", type: "dir" },
    { key: "themes", path: "wp-content/themes", type: "dir" },
    { key: "plugins", path: "wp-content/plugins", type: "dir" },
    { key: "config", path: "wp-config.php", type: "file" },
  ],
};

/** The shortcuts for a site type, or an empty list when none are known. */
export function appShortcuts(siteType) {
  return SHORTCUTS[siteType] ?? [];
}
