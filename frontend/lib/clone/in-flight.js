// Every storage access is wrapped: Safari private mode throws.
import { useSyncExternalStore } from "react";

const KEY = "sv-oss:clone-in-flight";

const listeners = new Set();

function subscribe(listener) {
  listeners.add(listener);
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
    // storage blocked; ignore
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

// useSyncExternalStore so the server snapshot is null without a setState in an effect.
export function useRememberedClone(applicationId) {
  return useSyncExternalStore(
    subscribe,
    () => recallClone(applicationId),
    () => null,
  );
}
