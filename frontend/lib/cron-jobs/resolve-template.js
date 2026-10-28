// A template's `{php}` becomes the picked site's own PHP binary, or plain `php` (the server default)
// for a typed path or a site without one; `{path}` becomes the directory once there is one, and
// stays until then so the form refuses to submit it. Older templates write `php` and pass unchanged.
export function resolveTemplate(template, { dir = "", site = null, placeholder = "{path}", phpPlaceholder = "{php}" } = {}) {
  const withPhp = template.replaceAll(phpPlaceholder, site?.php_binary || "php");
  const folder = dir.trim().replace(/\/+$/, "");
  return folder ? withPhp.replaceAll(placeholder, folder) : withPhp;
}
