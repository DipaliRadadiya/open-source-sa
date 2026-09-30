// The PHP version worth showing for an application, or null.
//
// A git application carries a php_version whatever it renders with — the
// create form asks for one on every rendering type — so a Node server-side
// app listed "PHP 8.4" in the PHP column. Only a git app that renders with PHP
// actually runs on it.
export function phpVersionShown(application) {
  if (!application?.php_version) return null;
  if (application.site_type === "git" && application.rendering_type && application.rendering_type !== "php") return null;
  return application.php_version;
}
