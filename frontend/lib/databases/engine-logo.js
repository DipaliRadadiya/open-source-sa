/**
 * The logo for a database engine, in a light and a dark variant.
 *
 * Two files per engine, unlike the application logos, because these three
 * brands set their name in near-black: on the dark theme's card the dolphin,
 * the sea lion and the leaf stay visible while the word beside them
 * disappears, leaving a coloured shape with no name attached.
 *
 * That was not obvious from measuring — a check counting "pixels lighter than
 * the background" passed MySQL at 100%, because it counted the bright dolphin
 * and never noticed the navy word. Looking at it on the dark card is what
 * showed the problem.
 *
 * Keyed on the engine identifier the API sends (`mysql`, `mariadb`,
 * `mongodb`). PostgreSQL is deliberately absent: no artwork was supplied for
 * it, and `null` gets the generic database glyph rather than a wrong logo.
 */
/*
 * `darkSize` exists because the supplied dark variants are not always the same
 * LOCKUP as the light one. MySQL's white file is a stacked mark — dolphin over
 * the word, 50×50 — where its light file is a wide horizontal wordmark at
 * 239×60. Rendered at the height that suits the wordmark, the stacked version
 * is twenty pixels tall and illegible, so it gets its own.
 */
const ENGINE_LOGOS = {
  mysql: { light: "mysql.svg", dark: "mysql-white.svg", darkSize: "h-8 w-auto max-w-12" },
  mariadb: { light: "mariadb.svg", dark: "mariadb-white.png" },
  mongodb: { light: "mongodb.png", dark: "mongodb-white.png" },
};

/** `{ light, dark }` public paths for an engine, or null when it has none. */
export function engineLogo(engine) {
  const pair = ENGINE_LOGOS[String(engine ?? "").toLowerCase()];
  if (!pair) return null;
  return {
    light: `/db-engines/${pair.light}`,
    dark: `/db-engines/${pair.dark}`,
    darkSize: pair.darkSize ?? null,
  };
}

export { ENGINE_LOGOS };
