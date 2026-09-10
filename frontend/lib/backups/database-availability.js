/**
 * Which sites actually have a database, for the backup form.
 *
 * A "files + database" backup of a site with no database succeeds and quietly
 * contains no database — the backend's own step returns early and records an
 * empty list. Nothing downstream says so, so the form has to, and to say it
 * the form needs to know how many databases the chosen site really has.
 *
 * `/databases` has no application filter, so this groups the list the panel
 * already fetches. Every row carries `application_id`.
 */
export function countByApplication(databases = []) {
  const counts = {};

  for (const database of databases ?? []) {
    const id = database?.application_id;
    // Server-level databases belong to no site and must not be credited to one.
    if (id === null || id === undefined) continue;

    counts[id] = (counts[id] ?? 0) + 1;
  }

  return counts;
}

/**
 * Does this site have a database? `null` means "we cannot tell".
 *
 * The three answers are deliberately distinct. Collapsing unknown into "no"
 * would put a confident "this site has no database" under a site whose
 * databases merely sat on a page we never loaded — a wrong warning on a backup
 * screen is worse than no warning, because it argues someone out of a setting
 * they actually need.
 */
export function hasNoDatabase(counts, known, applicationId) {
  if (!known || applicationId === null || applicationId === undefined) return null;

  return (counts?.[applicationId] ?? 0) === 0;
}

/**
 * The site options for a "which site is this database for?" picker.
 *
 * A site that already has one is offered but blocked, with the reason. Hiding
 * it instead would read as the list being broken — the site is right there in
 * the applications table — and the reason ("it already has one") is the thing
 * the reader has to act on.
 *
 * The one-per-site rule is the *attach* endpoint's, not create's: `POST
 * /databases` will happily attach a second. We apply the stricter rule anyway,
 * because staging and cloning both take `where(application_id)->first()`, so a
 * second database makes which-one-do-they-mean unanswerable — and because a
 * panel that lets you create a state its own edit screen refuses is a trap.
 */
/**
 * Does this KIND of site need a database at all?
 *
 * The difference between a warning and crying wolf. A static site, a git deploy
 * or a blank PHP site with no database is correct and always will be; only a
 * type that declares `needs_database` — WordPress, PrestaShop, NodeBB — is
 * missing something when it has none.
 *
 * Warning on the rest would put a permanent amber banner on sites that are
 * fine, which is how people learn to ignore the banner on the sites that are
 * not. Unknown type (list failed, or a type this panel does not know) answers
 * false for the same reason.
 *
 * `needs_database` lives on the site TYPE, never on the application, so this
 * always needs the types list to join against.
 */
export function siteNeedsDatabase(siteTypes = [], siteType) {
  if (!siteType) return false;

  return Boolean(
    (siteTypes ?? []).find((type) => type.name === siteType)?.needs_database,
  );
}

/**
 * The ids of sites that need a database and have none.
 *
 * Both halves are required. Listing every site with no database would flag
 * every static site on the server, permanently — and a marker that is on half
 * the list marks nothing.
 *
 * Returns an empty set when the counts are unknown, because an unread list must
 * never render as "none of these have a database".
 */
export function sitesMissingDatabase(applications = [], siteTypes = [], counts = null, known = false) {
  if (!known) return new Set();

  return new Set(
    (applications ?? [])
      .filter(
        (application) =>
          siteNeedsDatabase(siteTypes, application.site_type)
          && (counts?.[application.id] ?? 0) === 0,
      )
      .map((application) => application.id),
  );
}

/**
 * The site a database belongs to, or null.
 *
 * `DatabaseResource` carries `application_id` and no name, so every screen that
 * wants to *show* the site has to join against the applications list. Doing it
 * here keeps the loose-vs-strict id comparison in one place: the id arrives as
 * a number from the API and as a string from a form.
 */
export function applicationById(applications = [], applicationId) {
  if (applicationId === null || applicationId === undefined) return null;

  return (applications ?? []).find(
    (application) => String(application.id) === String(applicationId),
  ) ?? null;
}

export function applicationOptions(
  applications = [],
  counts = null,
  known = false,
  reason = "",
  // Why this site cannot speak the engine in question, or undefined. Passed as
  // a function because the create dialog's engine changes while the dialog is
  // open — a precomputed list would answer for the engine chosen a moment ago.
  engineReason = null,
) {
  return (applications ?? []).map((application) => {
    // The taken check first: "it already has one" is the more actionable of
    // the two, and a site can trip both.
    const taken = known && (counts?.[application.id] ?? 0) > 0 ? reason : undefined;

    return {
      value: String(application.id),
      label: application.name,
      hint: application.domain ?? undefined,
      // Unknown counts block nothing: see `hasNoDatabase`. Better to allow a
      // second attach the API may refuse than to bar a site that is free.
      disabledReason: taken ?? engineReason?.(application) ?? undefined,
    };
  });
}
