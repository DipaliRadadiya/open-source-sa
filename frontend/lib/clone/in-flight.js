/**
 * Which clone this browser last started for a given site, so a reload shows
 * the running job instead of an empty form (which invites a duplicate clone).
 *
 * Keyed per application id. `localStorage`, not a cookie: the poll endpoint is
 * the source of truth and this is only a pointer. Every access is wrapped
 * (Safari private mode throws); failure degrades to showing the form.
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
    // Ignored: the clone itself is unaffected.
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
 * The remembered clone id for this site, or null. A subscription so the server
 * snapshot is `null` and the real value arrives on hydration without a
 * setState-in-effect.
 */
export function useRememberedClone(applicationId) {
  return useSyncExternalStore(
    subscribe,
    () => recallClone(applicationId),
    () => null,
  );
}
