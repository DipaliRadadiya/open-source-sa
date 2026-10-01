/*
 * One-click jumps to well-known folders of a site type (e.g. WordPress uploads).
 * Only add a type after confirming its layout on a real installation, or a
 * chip will open a missing folder.
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
