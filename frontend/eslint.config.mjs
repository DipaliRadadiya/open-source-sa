import js from "@eslint/js";
import globals from "globals";
import nextCoreWebVitals from "eslint-config-next/core-web-vitals";

// `no-undef` on top of Next's preset. The preset leaves it off, so a reference
// to a prop that was never destructured — or an import dropped in a refactor —
// compiled fine and only blew up when the page rendered. That is exactly the
// class of mistake lint exists to catch.
const eslintConfig = [
  ...nextCoreWebVitals,
  {
    files: ["**/*.{js,jsx,mjs}"],
    languageOptions: {
      globals: { ...globals.browser, ...globals.node },
    },
    rules: {
      ...js.configs.recommended.rules,
      // Left to the editor: an unused import is untidy, not broken.
      "no-unused-vars": "off",
    },
  },
];

export default eslintConfig;
