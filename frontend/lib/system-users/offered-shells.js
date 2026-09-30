// `/bin/false` does exactly what "No login" does, so offering both reads as a
// choice that is not one. It stays in the list only for an account that
// already has it — otherwise that account's picker would have no current value.
const LEGACY_SHELLS = ["/bin/false"];

export function offeredShells(shells, current) {
  return shells.filter((shell) => !LEGACY_SHELLS.includes(shell.value) || shell.value === current);
}
