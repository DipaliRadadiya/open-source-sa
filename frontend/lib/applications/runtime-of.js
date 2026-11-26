import { phpVersionShown } from "@/lib/applications/php-version-shown";

export function runtimeOf(application) {
  const php = phpVersionShown(application);
  if (php) return { kind: "php", version: php };
  if (application?.node_version) return { kind: "node", version: application.node_version };
  const profile = application?.serving_profile ?? application?.rendering_type;
  if (profile === "docker" || application?.image) return { kind: "docker", version: null };
  if (profile === "static") return { kind: "static", version: null };
  return null;
}

export function runtimeLabel(runtime, t, tDocker, { full = false } = {}) {
  if (!runtime) return null;
  if (runtime.kind === "php") return t("phpFact", { version: runtime.version });
  if (runtime.kind === "node") return `Node.js ${full ? runtime.version : String(runtime.version).split(".")[0]}`;
  if (runtime.kind === "docker") return tDocker("title");
  return t("tiles.staticFiles");
}
