export function isTakenName(name, existingNames = []) {
  const typed = String(name ?? "").trim().toLowerCase();
  return Boolean(typed) && existingNames.some((existing) => String(existing).trim().toLowerCase() === typed);
}

export function markTakenName(error, form, name, existingNames) {
  if (error?.response?.status === 422 && error.response.data?.errors?.name && isTakenName(name, existingNames)) {
    form.setError("name", { type: "server", message: "nameTaken" });
  }
}
