export function resolveTemplate(template, { dir = "", site = null, placeholder = "{path}", phpPlaceholder = "{php}" } = {}) {
  const withPhp = template.replaceAll(phpPlaceholder, site?.php_binary || "php");
  const folder = dir.trim().replace(/\/+$/, "");
  return folder ? withPhp.replaceAll(placeholder, folder) : withPhp;
}
