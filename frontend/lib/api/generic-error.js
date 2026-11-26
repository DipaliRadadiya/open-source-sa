// For plain functions that can't call useTranslations; the app shell sets these on mount.
let message = "Something went wrong";

export function setGenericErrorMessage(next) {
  if (typeof next === "string" && next.trim()) message = next;
}

export function genericErrorMessage() {
  return message;
}

let rateLimited = "Too many requests. Wait a moment and try again.";

export function setRateLimitedMessage(next) {
  if (typeof next === "string" && next.trim()) rateLimited = next;
}

export function rateLimitedMessage() {
  return rateLimited;
}

let noAnswer =
  "No answer from the server. It may or may not have happened — reload the page to see where it stands.";

export function setNoAnswerMessage(next) {
  if (typeof next === "string" && next.trim()) noAnswer = next;
}

export function noAnswerMessage() {
  return noAnswer;
}

let serverRestarting = "The server is restarting a service. Try again in a moment.";

export function setServerRestartingMessage(next) {
  if (typeof next === "string" && next.trim()) serverRestarting = next;
}

export function serverRestartingMessage() {
  return serverRestarting;
}
