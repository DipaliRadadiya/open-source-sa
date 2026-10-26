// The File Manager lists the application's public_html. The API names that folder only
// inside `document_root` (public_html + web_root), so it is recovered by dropping web_root.
// Null when it cannot be told apart, so a copy falls back to the relative path.
export function filesRoot(application) {
  const documentRoot = (application?.document_root ?? "").replace(/\/+$/, "");
  if (!documentRoot) return null;
  const webRoot = (application?.web_root ?? "").replace(/^\/+|\/+$/g, "");
  if (!webRoot) return documentRoot;
  return documentRoot.endsWith(`/${webRoot}`) ? documentRoot.slice(0, -(webRoot.length + 1)) : null;
}

/** Where `path` (relative to the File Manager's root) is on the server. */
export function serverPath(root, path = "") {
  if (!root) return path;
  return path ? `${root}/${path}` : root;
}
