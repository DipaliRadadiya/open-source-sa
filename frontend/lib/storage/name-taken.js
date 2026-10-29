// The backend's unique-name refusal reads "The name has already been taken."; when the typed name
// matches another destination, the form says it in its own words instead (testers' sheet, 29 Oct).
export function isTakenName(name, existingNames = []) {
  const typed = String(name ?? "").trim().toLowerCase();
  return Boolean(typed) && existingNames.some((existing) => String(existing).trim().toLowerCase() === typed);
}

export function markTakenName(error, form, name, existingNames) {
  if (error?.response?.status === 422 && error.response.data?.errors?.name && isTakenName(name, existingNames)) {
    form.setError("name", { type: "server", message: "nameTaken" });
  }
}
