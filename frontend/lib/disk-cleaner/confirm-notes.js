export function keepApplicable(chosen = []) {
  return chosen.filter((category) => category?.keeps_days !== false);
}

export function confirmNotes(chosen = []) {
  return {
    keepApplies: keepApplicable(chosen).length > 0,
    truncates: chosen.some((category) => category?.method === "truncate"),
  };
}
