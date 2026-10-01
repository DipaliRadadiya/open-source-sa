// Mirrors `App\Rules\BotUserAgent`; keep in step, or the client accepts what the server refuses.
// Ends up in a web-server regex written by an elevated process, hence an allowlist, not escaping.

// Exported for `tests/backend-mirror.test.mjs`.
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

// A message key, or null. Whole value, never a prefix: `Applebot-Extended` is a legitimate opt-out token.
export function botRuleError(value) {
  const trimmed = String(value ?? "").trim();

  if (trimmed === "") return null;
  if (!SHAPE.test(trimmed)) return "invalid";

  const lower = trimmed.toLowerCase();

  if (CATCH_ALLS.has(lower)) return "tooBroad";
  if (SEARCH_ENGINES.has(lower)) return "searchEngine";
  // The web server matches any part of the user agent, so this would block browsers or search engines.
  if (BROWSER_USER_AGENTS.some((agent) => agent.includes(lower))) return "browserWord";
  if (SEARCH_USER_AGENTS.some((agent) => agent.includes(lower))) return "searchEngine";

  return null;
}

// Real visitors' user agents, lower-cased; any part of one would block people.
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

// Policy list plus additions, minus exemptions; mirrors `botBlockPattern()`, where allow beats block.
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
