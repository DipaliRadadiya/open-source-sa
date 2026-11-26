const SERVED_AS = { php: "php", ssr: "node", csr: "static", static: "static" };

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
