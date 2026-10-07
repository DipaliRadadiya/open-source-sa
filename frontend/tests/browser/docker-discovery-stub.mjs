/**
 * DS-02 answers for neosmemo/memos, taken from Docker Hub and the registry on
 * 2026-10-07 (search, tags, and the 0.31.0 amd64 config: EXPOSE 5230,
 * VOLUME /var/opt/memos, ENV TZ, MEMOS_PORT).
 */
const json = (body, status = 200) => ({ status, contentType: "application/json", body: JSON.stringify(body) });

export function discoveryStub(url) {
  const u = new URL(url);
  const kind = u.pathname.split("/").pop();
  if (kind === "search") {
    const q = (u.searchParams.get("q") ?? "").toLowerCase();
    if (!"memos".startsWith(q) && !q.includes("memos")) return json({ results: [] });
    return json({
      results: [
        { image: "neosmemo/memos", registry: "docker.io", description: "A privacy-first, lightweight note-taking service.", stars: 233, pulls: 11672941, official: false, verified_publisher: false },
        { image: "elestio/memos", registry: "docker.io", description: "Memos, verified and packaged by Elestio", stars: 21, pulls: 102962, official: false, verified_publisher: false },
        { image: "lincolnthalles/memos", registry: "docker.io", description: "Optimized images of neosmemo/memos", stars: 0, pulls: 58198, official: false, verified_publisher: false },
      ],
    });
  }
  if (kind === "tags") {
    return json({
      image: u.searchParams.get("image"),
      recommended: "0.31.0",
      tags: [
        { name: "0.31.0", stable: true }, { name: "0.31", stable: true }, { name: "0.30.0", stable: true },
        { name: "stable", stable: false }, { name: "canary", stable: false },
      ],
    });
  }
  const image = u.searchParams.get("image") ?? "";
  if (!image.startsWith("neosmemo/memos:")) return json({ image, found: false, message: "No image with that name and version was found." });
  return json({
    image, digest: "sha256:fixture", found: true,
    exposed_ports: [5230], suggested_port: 5230, port_confidence: "declared",
    volumes: ["/var/opt/memos"], suggested_volumes: [{ path: "/var/opt/memos", name: "memos-data" }],
    env: [{ key: "TZ", default: "UTC", required: false }, { key: "MEMOS_PORT", default: "5230", required: false }],
    workdir: "/var/opt/memos", user: "root", size_bytes: 26239339, architectures: ["amd64", "arm64"],
    uses_app_dir: false, warnings: [],
  });
}
