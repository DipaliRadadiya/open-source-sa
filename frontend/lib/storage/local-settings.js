// Compatibility only: APIs before RC-39 did not send their local-folder settings.
// When metadata is present, its roots and default always win (including an empty roots list).
const LEGACY_LOCAL_SETTINGS = {
  allowed_roots: ["/var/backups", "/mnt", "/media"],
  default_path: "/var/backups/panel",
};

export function localStorageSettings(settings) {
  return settings ?? LEGACY_LOCAL_SETTINGS;
}
