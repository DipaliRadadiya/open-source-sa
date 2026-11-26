// Records toasts instead of rendering them; each test reads globalThis.__toasts.
const record = (kind) => (message, options) => {
  (globalThis.__toasts ??= []).push({ kind, message });
  if (options?.description) (globalThis.__toastDescriptions ??= []).push(options.description);
};
export const toast = { success: record("success"), error: record("error"), warning: record("warning"), info: record("info") };
