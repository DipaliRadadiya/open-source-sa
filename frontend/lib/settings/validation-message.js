// Settings schemas emit key tokens, translated here. Anything unrecognised is
// an already localised API message and is shown as-is.
const KEYS = [
  "required",
  "tooLong",
  "invalidHostname",
  "hostnameTooLong",
  "invalidNumber",
  "swapTooLarge",
  "invalidPort",
  "invalidTime",
  "invalidHour",
  "invalidMemory",
  "passwordTooShort",
];

export function validationMessage(t, text) {
  if (!text) return null;
  return KEYS.includes(text) ? t(text) : text;
}
