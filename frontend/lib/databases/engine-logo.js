// Dark variants because the wordmarks vanish on dark cards; unknown engines → null.
// `wordmark`: the file spells the name. `darkSize`: the dark lockup differs.
const ENGINE_LOGOS = {
  mysql: { light: "mysql.svg", dark: "mysql-white.svg", wordmark: true },
  mariadb: { light: "mariadb.svg", dark: "mariadb-white.png", wordmark: true },
  mongodb: { light: "mongodb.png", dark: "mongodb-white.png", wordmark: true },
  // Same file on both themes on purpose; `size` because it is square.
  postgresql: {
    light: "postgresql.svg",
    dark: "postgresql.svg",
    size: "h-8 w-auto max-w-12",
    // Mark only, no name: callers must print the name beside it.
    wordmark: false,
  },
};

/** `{ light, dark }` public paths for an engine, or null when it has none. */
export function engineLogo(engine) {
  const pair = ENGINE_LOGOS[String(engine ?? "").toLowerCase()];
  if (!pair) return null;
  return {
    light: `/db-engines/${pair.light}`,
    dark: `/db-engines/${pair.dark}`,
    size: pair.size ?? null,
    darkSize: pair.darkSize ?? pair.size ?? null,
    wordmark: pair.wordmark === true,
  };
}

export { ENGINE_LOGOS };
