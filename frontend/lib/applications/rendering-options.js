// How each rendering type is served, as the backend decides it (ServingProfile::resolve):
// only server-side rendering runs a process; client-side and static are a directory of files.
const SERVED_AS = { php: "php", ssr: "node", csr: "static", static: "static" };

// Drops the rendering types this server cannot serve (no PHP on a Node server), so the form
// never offers a choice the API refuses on submit. Without PHP the type's PHP version picker
// goes too: it has nothing to list there, and only said "no choices". Unknown profiles, or a
// filter that would leave nothing to pick, change nothing: the API's refusal is the last word.
export function withServableRenderingTypes(siteTypes, servingProfiles) {
  if (!Array.isArray(servingProfiles) || servingProfiles.length === 0) return siteTypes;
  const servesPhp = servingProfiles.includes("php");

  return siteTypes.map((type) => {
    const rendering = type.fields?.find((field) => field.name === "rendering_type");
    if (!rendering || !Array.isArray(rendering.options)) return type;

    const options = rendering.options.filter(
      (option) => !SERVED_AS[option.value] || servingProfiles.includes(SERVED_AS[option.value]),
    );
    if (!options.length) return type;

    return {
      ...type,
      fields: type.fields
        .filter((field) => servesPhp || field.source !== "php_versions")
        .map((field) => (field === rendering ? { ...field, options } : field)),
    };
  });
}
