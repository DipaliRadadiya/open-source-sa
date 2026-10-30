// Which confirm-dialog notes apply to what is ticked. `keeps_days: false` (Docker images,
// unused packages) ignores the keep choice; `method: "truncate"` empties live log files.

/** The ticked categories that the keep choice applies to. */
export function keepApplicable(chosen = []) {
  return chosen.filter((category) => category?.keeps_days !== false);
}

/** Whether to show the keep line and the "active log files are emptied" note. */
export function confirmNotes(chosen = []) {
  return {
    keepApplies: keepApplicable(chosen).length > 0,
    truncates: chosen.some((category) => category?.method === "truncate"),
  };
}
