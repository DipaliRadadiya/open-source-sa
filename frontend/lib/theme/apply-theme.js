export function buildThemeStyles(palette) {
  if (!palette) return "";

  const { shades, light, dark } = palette;

  const shadeVars = Object.entries(shades)
    .map(([step, value]) => `--primary-${step}: ${value};`)
    .join(" ");

  return `
html:root {
  ${shadeVars}
  --primary: ${light.primary};
  --primary-foreground: ${light.primaryForeground};
}
html.dark {
  --primary: ${dark.primary};
  --primary-foreground: ${dark.primaryForeground};
}
`;
}
