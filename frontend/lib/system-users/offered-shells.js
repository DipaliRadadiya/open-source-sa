// `/bin/false` behaves like "No login", so it is offered only to an account that
// already has it; otherwise that account's picker would have no current value.
const LEGACY_SHELLS = ["/bin/false"];

export function offeredShells(shells, current) {
  return shells.filter((shell) => !LEGACY_SHELLS.includes(shell.value) || shell.value === current);
}
