const SHORTCUTS = {
  wordpress: [
    { key: "uploads", path: "wp-content/uploads", type: "dir" },
    { key: "themes", path: "wp-content/themes", type: "dir" },
    { key: "plugins", path: "wp-content/plugins", type: "dir" },
    { key: "config", path: "wp-config.php", type: "file" },
  ],
};

export function appShortcuts(siteType) {
  return SHORTCUTS[siteType] ?? [];
}
