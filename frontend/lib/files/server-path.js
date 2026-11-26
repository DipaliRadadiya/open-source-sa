export function filesRoot(application) {
  const documentRoot = (application?.document_root ?? "").replace(/\/+$/, "");
  if (!documentRoot) return null;
  const webRoot = (application?.web_root ?? "").replace(/^\/+|\/+$/g, "");
  if (!webRoot) return documentRoot;
  return documentRoot.endsWith(`/${webRoot}`) ? documentRoot.slice(0, -(webRoot.length + 1)) : null;
}

export function serverPath(root, path = "") {
  if (!root) return path;
  return path ? `${root}/${path}` : root;
}
