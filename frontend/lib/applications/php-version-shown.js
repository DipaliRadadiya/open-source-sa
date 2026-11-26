export function phpVersionShown(application) {
  if (!application?.php_version) return null;
  if (application.site_type === "git" && application.rendering_type && application.rendering_type !== "php") return null;
  return application.php_version;
}
