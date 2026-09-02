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
      // An unused import is not just untidy: with no TypeScript it is usually
      // the residue of a refactor that stopped halfway, which is how a
      // component came to sit in the tree with no importer at all. `args` is
      // left alone because table cell renderers and event handlers legitimately
      // ignore parameters, and `^_` is the escape hatch for a binding kept on
      // purpose.
      "no-unused-vars": ["error", { args: "none", varsIgnorePattern: "^_" }],
    },
  },
];

export default eslintConfig;
