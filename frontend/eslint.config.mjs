import js from "@eslint/js";
import globals from "globals";
import nextCoreWebVitals from "eslint-config-next/core-web-vitals";

// no-undef: Next's preset leaves it off, so a missing variable only failed at render.
const eslintConfig = [
  ...nextCoreWebVitals,
  {
    files: ["**/*.{js,jsx,mjs}"],
    languageOptions: {
      globals: { ...globals.browser, ...globals.node },
    },
    rules: {
      ...js.configs.recommended.rules,
      "no-unused-vars": ["error", { args: "none", varsIgnorePattern: "^_" }],
    },
  },
];

export default eslintConfig;
