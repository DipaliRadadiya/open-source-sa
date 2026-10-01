/**
 * A bot name someone types in, checked by the same rules as
 * `App\Rules\BotUserAgent`. Keep in step: a laxer client rule promises an
 * acceptance the server will refuse.
 *
 * The value ends up inside a web-server regex written by an elevated process,
 * hence a charset allowlist rather than escaping. The value is matched
 * case-insensitively within the user agent, so broad words (`bot`) would also
 * block search engines.
 */

/**
 * Letters, digits and the punctuation real crawler tokens use. Exported for
 * `tests/backend-mirror.test.mjs`.
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
 * Compared against the whole value, never a prefix: `applebot` is a search
 * engine but `Applebot-Extended` is a legitimate opt-out token.
 */
export function botRuleError(value) {
  const trimmed = String(value ?? "").trim();

  if (trimmed === "") return null;
  if (!SHAPE.test(trimmed)) return "invalid";

  const lower = trimmed.toLowerCase();

  if (CATCH_ALLS.has(lower)) return "tooBroad";
  if (SEARCH_ENGINES.has(lower)) return "searchEngine";
  // The web server matches any part of the user agent, so a word inside a
  // browser's or search engine's user agent would block it.
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
 * What is actually enforced: the policy's list plus this site's additions,
 * minus its exemptions. Mirrors `AbstractWebServerDriver::botBlockPattern()`,
 * including that an allow beats a block of the same name.
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
