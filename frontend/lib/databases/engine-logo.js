/**
 * Database engine logos, keyed on the API's engine identifier, with light and
 * dark variants (the wordmarks are near-black and vanish on dark cards).
 * Unknown engines resolve to null so callers show the generic glyph.
 *
 * `wordmark` says whether the file spells the engine's name; callers print the
 * name only when it does not.
 * `darkSize` is for a dark variant with a different lockup from the light one.
 */
const ENGINE_LOGOS = {
  mysql: { light: "mysql.svg", dark: "mysql-white.svg", wordmark: true },
  mariadb: { light: "mariadb.svg", dark: "mariadb-white.png", wordmark: true },
  mongodb: { light: "mongodb.png", dark: "mongodb-white.png", wordmark: true },
  /*
   * Same file on both themes on purpose: the mid-blue mark reads on both.
   * `size` because it is square where the others are wide.
   */
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
