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
