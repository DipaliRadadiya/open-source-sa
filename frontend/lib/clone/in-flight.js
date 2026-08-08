/**
 * Which clone this browser last started for a given site.
 *
 * The progress card tells people they can leave the page, which was a lie the
 * moment they did: the clone id lived in React state only, so a reload came
 * back to an empty form with a job still running behind it and no way to tell.
 * The obvious next move — start it again — makes a second site.
 *
 * Stored per application id rather than as a single value, so watching one
 * clone does not forget another. `localStorage` and not a cookie because
 * nothing server-side needs it; the poll endpoint is the source of truth and
 * this is only a pointer at it.
 *
 * Every access is wrapped: Safari in private mode throws on `localStorage`,
 * and losing the pointer must degrade to "show the form", never to a crash.
 */
import { useSyncExternalStore } from "react";

const KEY = "sv-oss:clone-in-flight";

const listeners = new Set();

function subscribe(listener) {
  listeners.add(listener);
  // Another tab finishing or starting a clone counts too.
  window.addEventListener("storage", listener);
  return () => {
    listeners.delete(listener);
    window.removeEventListener("storage", listener);
  };
}

function announce() {
  listeners.forEach((listener) => listener());
}

function read() {
  if (typeof window === "undefined") return {};
  try {
    const raw = window.localStorage.getItem(KEY);
    const parsed = raw ? JSON.parse(raw) : {};
    return parsed && typeof parsed === "object" ? parsed : {};
  } catch {
    return {};
  }
}

function write(value) {
  try {
    window.localStorage.setItem(KEY, JSON.stringify(value));
  } catch {
    // Nothing to do and nothing worth telling anyone: the clone is unaffected.
  }
  announce();
}

export function rememberClone(applicationId, cloneId) {
  if (typeof window === "undefined" || !applicationId || !cloneId) return;
  write({ ...read(), [String(applicationId)]: cloneId });
}

export function recallClone(applicationId) {
  return read()[String(applicationId)] ?? null;
}

export function forgetClone(applicationId) {
  if (typeof window === "undefined") return;
  const all = read();
  delete all[String(applicationId)];
  write(all);
}

/**
 * The remembered clone id for this site, or null.
 *
 * Subscribed rather than read once in an effect so the server snapshot is
 * honestly `null` — the server has no idea what this browser started — and the
 * real value arrives on hydration without a setState-in-effect.
 */
export function useRememberedClone(applicationId) {
  return useSyncExternalStore(
    subscribe,
    () => recallClone(applicationId),
    () => null,
  );
}
