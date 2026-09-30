/**
 * A bot name someone types in, checked by the same rules the backend uses.
 *
 * Copied from `App\Rules\BotUserAgent` deliberately, and it has to change with
 * it: a laxer client rule is worse than none, because it promises an
 * acceptance the server is about to refuse.
 *
 * The value ends up inside a regex in an nginx `if`, an Apache
 * `SetEnvIfNoCase` or an OLS rewrite, written by an elevated process — hence
 * the charset allowlist rather than escaping. The other two refusals are not
 * about safety but about intent: the pattern is matched case-insensitively
 * against the start of the user agent, so `bot` matches `Googlebot` and
 * `bingbot`. Someone typing that means "block bots" and gets "disappear from
 * search", with nothing on screen to explain why.
 */

/**
 * Letters, digits and the punctuation real crawler tokens use.
 *
 * Exported only so `tests/backend-mirror.test.mjs` can hold it up against the
 * PHP it was copied from.
 */
export const SHAPE = /^[A-Za-z0-9._\-/]{2,100}$/;

/** Values that match a legitimate crawler, or everything. */
export const CATCH_ALLS = new Set([
  "bot", "bots", "crawler", "crawl", "spider", "agent", "search",
  "*", ".*", ".", "a", "mozilla", "http", "www",
]);

/** Blocking these is never what anyone meant by "block AI bots". */
export const SEARCH_ENGINES = new Set([
  "googlebot", "google", "bingbot", "bing", "duckduckbot",
  "yandexbot", "baiduspider", "slurp", "applebot",
]);

/** The backend's own cap on each list. */
export const BOT_RULE_LIMIT = 50;

/**
 * Why this value cannot be used, as a message key, or null when it can.
 *
 * `applebot` is a search engine but `Applebot-Extended` is the training
 * opt-out token, and blocking that is legitimate — so the comparison is
 * against the whole value, never a prefix.
 */
export function botRuleError(value) {
  const trimmed = String(value ?? "").trim();

  if (trimmed === "") return null;
  if (!SHAPE.test(trimmed)) return "invalid";

  const lower = trimmed.toLowerCase();

  if (CATCH_ALLS.has(lower)) return "tooBroad";
  if (SEARCH_ENGINES.has(lower)) return "searchEngine";
  // The web server matches any PART of the user agent, so a word inside a
  // browser's own name blocks real people: `Chrome` + `Safari` gave every
  // Chrome visitor a 403, `Android` every phone. `Googlebot/2.1` slipped past
  // the exact-name check above the same way.
  if (BROWSER_USER_AGENTS.some((agent) => agent.includes(lower))) return "browserWord";
  if (SEARCH_USER_AGENTS.some((agent) => agent.includes(lower))) return "searchEngine";

  return null;
}

// Real visitors' user agents, lower-cased: anything that is part of one of
// these would block people rather than bots.
const BROWSER_USER_AGENTS = [
  "mozilla/5.0 (windows nt 10.0; win64; x64) applewebkit/537.36 (khtml, like gecko) chrome/129.0.0.0 safari/537.36 edg/129.0.0.0",
  "mozilla/5.0 (macintosh; intel mac os x 10_15_7) applewebkit/605.1.15 (khtml, like gecko) version/18.0 safari/605.1.15",
  "mozilla/5.0 (x11; linux x86_64; rv:131.0) gecko/20100101 firefox/131.0",
  "mozilla/5.0 (iphone; cpu iphone os 18_0 like mac os x) applewebkit/605.1.15 (khtml, like gecko) version/18.0 mobile/15e148 safari/604.1",
  "mozilla/5.0 (linux; android 14; pixel 8) applewebkit/537.36 (khtml, like gecko) chrome/129.0.0.0 mobile safari/537.36 samsungbrowser/26.0 opr/114.0.0.0",
];
const SEARCH_USER_AGENTS = [
  "mozilla/5.0 (compatible; googlebot/2.1; +http://www.google.com/bot.html)",
  "mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)",
  "mozilla/5.0 (compatible; yandexbot/3.0; +http://yandex.com/bots)",
  "duckduckbot/1.1; (+http://duckduckgo.com/duckduckbot.html)",
  "mozilla/5.0 (compatible; baiduspider/2.0; +http://www.baidu.com/search/spider.html)",
  "mozilla/5.0 (macintosh; intel mac os x 10_15_5) applewebkit/605.1.15 (khtml, like gecko) version/13.1.1 safari/605.1.15 (applebot/0.1; +http://www.apple.com/go/applebot)",
];


/** Case-insensitive membership, matching how the vhost compares these. */
export function hasBot(list, value) {
  const lower = String(value ?? "").trim().toLowerCase();
  return list.some((entry) => String(entry).toLowerCase() === lower);
}

/**
 * What is actually enforced: the policy's own list, plus this site's
 * additions, minus its exemptions.
 *
 * Mirrors `AbstractWebServerDriver::botBlockPattern()`, including that an
 * allow beats a block of the same name — a rule that says allow and one that
 * says block have only one safe resolution, and the one that keeps traffic
 * flowing is it. Without this the card would go on reporting the policy's
 * count while enforcing something else.
 */
export function effectiveBlockedBots(policyBots = [], blocked = [], allowed = []) {
  const allow = new Set(allowed.map((bot) => String(bot).toLowerCase()));
  const seen = new Set();

  return [...policyBots, ...blocked].filter((bot) => {
    const lower = String(bot).toLowerCase();
    if (allow.has(lower) || seen.has(lower)) return false;
    seen.add(lower);
    return true;
  });
}
