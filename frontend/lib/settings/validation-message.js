// The settings schemas emit key tokens instead of English so the same rule can
// be worded in every locale (same convention as the firewall add-rule form).
// Anything unrecognised is a message the API sent, already localized — it is
// shown as-is rather than swallowed.
const KEYS = [
  "required",
  "tooLong",
  "invalidHostname",
  "invalidNumber",
  "swapTooLarge",
  "invalidPort",
  "invalidTime",
  "invalidMemory",
  "passwordTooShort",
];

export function validationMessage(t, text) {
  if (!text) return null;
  return KEYS.includes(text) ? t(text) : text;
}
