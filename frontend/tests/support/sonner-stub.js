// Records toasts instead of rendering them; each test reads globalThis.__toasts.
const record = (kind) => (message) => (globalThis.__toasts ??= []).push({ kind, message });
export const toast = { success: record("success"), error: record("error") };
