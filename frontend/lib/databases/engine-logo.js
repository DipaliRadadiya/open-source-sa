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
 * Keyed on the engine identifier the API sends. An engine with no entry
 * resolves to null and gets the generic database glyph, rather than a guessed
 * path that renders as a broken image.
 */
/*
 * `darkSize` exists because a dark variant need not be the same LOCKUP as its
 * light one, and then it cannot share its height.
 *
 * MySQL no longer needs it. The white file shipped with the set was a stacked
 * mark — dolphin over the word, 50×50 — against a 239×60 horizontal wordmark on
 * the light theme, so the logo changed SHAPE with the theme and had to be drawn
 * taller to stay legible. MySQL's mark is a single flat colour, so the white
 * variant is now the same horizontal file with that colour swapped: same
 * lockup, same height, both themes.
 */
const ENGINE_LOGOS = {
  mysql: { light: "mysql.svg", dark: "mysql-white.svg" },
  mariadb: { light: "mariadb.svg", dark: "mariadb-white.png" },
  mongodb: { light: "mongodb.png", dark: "mongodb-white.png" },
  /*
   * The same file on both themes, and that is not an oversight. The other
   * three are wordmarks whose lettering is near-black; PostgreSQL's official
   * mark is the elephant alone, in a mid-blue that reads on white and on the
   * dark card alike — measured at 86% of its ink lighter than the dark
   * surface. A white variant would be a redraw nobody published.
   *
   * `size` rather than `darkSize`: it is square where the others are wide, so
   * at the height that suits a wordmark it would be a 20px thumbnail.
   */
  postgresql: {
    light: "postgresql.svg",
    dark: "postgresql.svg",
    size: "h-8 w-auto max-w-12",
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
  };
}

export { ENGINE_LOGOS };
