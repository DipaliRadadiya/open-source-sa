/**
 * The containerised database engines, shaped like site-type cards.
 *
 * Not site types, and they never become any: `domain` is required for every
 * application here and provisioning always writes a vhost, so a database as a
 * "site" would hold a domain nobody types and be issued a certificate no browser
 * can use. What this does is let them appear in the one grid people actually look
 * in — reported three times as missing from it — while clicking one opens the
 * database dialog instead of selecting a site type.
 *
 * `engine` on the card is the marker the picker branches on. A real site type never
 * has it, so there is no way for one of these to be mistaken for an application by
 * code that does not know about them.
 */
export function databaseTypeCards(engines = [], t) {
  return engines.map((engine) => ({
    // Namespaced, so it can never collide with a site type's name and so a stray
    // `?type=` query parameter cannot select one as an application.
    name: `database:${engine.name}`,
    title: engine.label,
    tagline: t("tagline", { version: engine.versions[0] ?? "" }),
    category: "database",
    // Nothing to install and nothing to block: the engine runs as a container, and
    // the server already hosts containers or this list would be empty.
    available: true,
    blockers: [],
    // Deliberately not `popular`. That flag orders the grid and opens it, and these
    // would then be the first thing somebody creating a WordPress site sees.
    popular: false,
    // What the picker hands back instead of a site type.
    engine,
  }));
}
