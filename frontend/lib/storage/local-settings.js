const LEGACY_LOCAL_SETTINGS = {
  allowed_roots: ["/var/backups", "/mnt", "/media"],
  default_path: "/var/backups/panel",
};

export function localStorageSettings(settings) {
  return settings ?? LEGACY_LOCAL_SETTINGS;
}
