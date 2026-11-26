// fail2ban reads % as interpolation, so it has to be escaped.

const PATH = /^\/[A-Za-z0-9._~/-]*$/;

export const LOGIN_ANSWERS = {
  error: "(?:401|403|422|429)",
  page: "200",
};

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

export function usesWordPressRule(filter) {
  return /wp-login|xmlrpc/.test(filter ?? "");
}
