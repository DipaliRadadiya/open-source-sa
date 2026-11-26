import { noAnswerMessage, rateLimitedMessage, serverRestartingMessage } from "./generic-error.js";
import { isRestartBlip } from "./restart-blip.js";

const FRAMEWORK_RATE_LIMIT = /^too many (attempts|requests)\.?$/i;
const FRAMEWORK_SERVER_ERROR = /^server error\.?$/i;

export function isUnansweredWrite(error) {
  if (!error?.isAxiosError || error.response || error.code === "ERR_CANCELED") return false;
  const method = String(error.config?.method ?? "get").toLowerCase();
  return !["get", "head", "options"].includes(method);
}

// 504 or no answer: the server usually finishes the work anyway.
export function mayStillBeRunning(error) {
  return error?.response?.status === 504 || isUnansweredWrite(error);
}

export function apiMessage(error, fallback, { reference: withReference = true } = {}) {
  if (isUnansweredWrite(error)) return noAnswerMessage();
  if (isRestartBlip(error)) return serverRestartingMessage();

  const data = error?.response?.data;
  const message = data?.message;
  const trimmed = typeof message === "string" ? message.trim() : "";

  if (error?.response?.status === 429 && (!trimmed || FRAMEWORK_RATE_LIMIT.test(trimmed))) {
    return rateLimitedMessage();
  }
  const reference =
    withReference && typeof data?.reference === "string" ? data.reference.trim() : "";

  const usable = FRAMEWORK_SERVER_ERROR.test(trimmed) ? "" : trimmed;
  const sentence = !usable || (!/\s/.test(usable) && /[/.]/.test(usable)) ? fallback : usable;

  if (!reference) return sentence;
  if (typeof sentence === "string" && sentence.includes(reference)) return sentence;
  if (!sentence) return reference;

  return `${sentence} · ${reference}`;
}
