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
 *
 * `wordmark` says whether the file spells the engine's name. Three of these do,
 * so printing the name beside them reads "MySQL MySQL"; PostgreSQL's does not,
 * and a caller using the logo as the sole identity has to know the difference.
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
  mysql: { light: "mysql.svg", dark: "mysql-white.svg", wordmark: true },
  mariadb: { light: "mariadb.svg", dark: "mariadb-white.png", wordmark: true },
  mongodb: { light: "mongodb.png", dark: "mongodb-white.png", wordmark: true },
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
    /*
     * The one mark with no name in it. Anywhere the logo is the only identity
     * on screen, this engine needs its name printed beside it — an elephant
     * says "PostgreSQL" to people who already knew, which is not who a first
     * database screen is for.
     */
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
