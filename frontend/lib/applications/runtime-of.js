import { phpVersionShown } from "@/lib/applications/php-version-shown";

// What an application runs on, for lists: PHP with its version, Node.js with its
// version, a container, or plain files. `null` while the API has not said.
export function runtimeOf(application) {
  const php = phpVersionShown(application);
  if (php) return { kind: "php", version: php };
  if (application?.node_version) return { kind: "node", version: application.node_version };
  const profile = application?.serving_profile ?? application?.rendering_type;
  if (profile === "docker" || application?.image) return { kind: "docker", version: null };
  if (profile === "static") return { kind: "static", version: null };
  return null;
}

// The words for a runtime. `t` is the "applications" namespace, `tDocker` "docker".
// Node shows its major line ("Node.js 24", as in the prototype); `full` gives the
// exact version, for a tooltip.
export function runtimeLabel(runtime, t, tDocker, { full = false } = {}) {
  if (!runtime) return null;
  if (runtime.kind === "php") return t("phpFact", { version: runtime.version });
  if (runtime.kind === "node") return `Node.js ${full ? runtime.version : String(runtime.version).split(".")[0]}`;
  if (runtime.kind === "docker") return tDocker("title");
  return t("tiles.staticFiles");
}
