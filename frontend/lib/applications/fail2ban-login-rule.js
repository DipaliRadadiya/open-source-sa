/**
 * A Fail2ban filter for one login page, written from two plain answers.
 *
 * The backend's default filter only knows WordPress (wp-login.php). For any other
 * application the person names the address the sign-in form posts to and how the
 * site answers a wrong password. Only that answer is counted, so a good sign-in,
 * which almost always redirects, never adds up to a ban (backend bug #93).
 */

// fail2ban reads `%` as interpolation and the backend fills `{…}`, so neither may appear.
const PATH = /^\/[A-Za-z0-9._~/-]*$/;

export const LOGIN_ANSWERS = {
  error: "(?:401|403|422|429)",
  page: "200",
};

// Read back by `parseLoginRule`, so the box shows what the saved filter watches.
const MARKER = /^# Login page: (\/\S*) \(wrong password: (error|page)\)$/m;

export function validLoginPath(path) {
  return PATH.test(path) && path.length <= 200;
}

function escapeRegex(text) {
  return text.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

export function loginFilter(path, answer) {
  const status = LOGIN_ANSWERS[answer] ?? LOGIN_ANSWERS.error;
  return [
    `# Login page: ${path} (wrong password: ${answer in LOGIN_ANSWERS ? answer : "error"})`,
    "[Definition]",
    `failregex = ^<HOST> .* "POST ${escapeRegex(path)}(?:\\?[^"]*)? HTTP/[^"]*" ${status}\\b`,
    "ignoreregex =",
    "",
  ].join("\n");
}

export function parseLoginRule(filter) {
  const match = MARKER.exec(filter ?? "");
  return match ? { path: match[1], answer: match[2] } : null;
}

/** Still the WordPress default, so on another site type it bans nobody. */
export function usesWordPressRule(filter) {
  return /wp-login|xmlrpc/.test(filter ?? "");
}
