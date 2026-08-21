/**
 * Which phpMyAdmin control a database should get, decided before the click.
 *
 * The SSO endpoint refuses for five different reasons. Two of them are
 * knowable from data the page already holds, and those are the two that used
 * to be discovered by being told no:
 *
 *   hidden      MongoDB. phpMyAdmin does not speak it, and the API answers 422.
 *   install     No active phpMyAdmin site on the server. Same condition the
 *               backend checks: site_type=phpmyadmin AND status=active.
 *   needs-user  phpMyAdmin signs in AS a database user. With none, there is no
 *               login to make.
 *   open        Everything we can see is in order — which is not a promise.
 *               A site sharing the server-wide PHP pool, or one that cannot
 *               prepare the link, still refuses, and the toast still handles it.
 *
 * `installed` is deliberately three-valued. `null` means the lookup failed, and
 * that is NOT evidence there is no phpMyAdmin — offering to install a second
 * copy because one request timed out would be worse than the error it replaces.
 * Unknown behaves exactly as the old code did.
 */
export function phpmyadminState({ engine, installed = null, users = null } = {}) {
  if (engine === "mongodb") return "hidden";
  if (installed === false) return "install";
  // Only when we positively counted zero. A missing count is not zero users.
  if (users === 0) return "needs-user";
  return "open";
}

/**
 * The user count a database row carries, whichever shape it arrived in.
 *
 * The list sends `users_count`; the detail payload sends the `users` array. A
 * component rendered in both places would otherwise read undefined in one of
 * them and quietly decide there are no users.
 */
export function userCount(database) {
  if (typeof database?.users_count === "number") return database.users_count;
  if (Array.isArray(database?.users)) return database.users.length;
  return null;
}
