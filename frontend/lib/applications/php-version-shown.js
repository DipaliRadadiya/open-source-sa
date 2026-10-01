// The PHP version worth showing for an application, or null. Git apps carry a
// php_version whatever they render with; only those rendering with PHP use it.
export function phpVersionShown(application) {
  if (!application?.php_version) return null;
  if (application.site_type === "git" && application.rendering_type && application.rendering_type !== "php") return null;
  return application.php_version;
}
