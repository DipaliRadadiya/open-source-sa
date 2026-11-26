// /bin/false works like no login, so only offer it to an account that already has it.
const LEGACY_SHELLS = ["/bin/false"];

export function offeredShells(shells, current) {
  return shells.filter((shell) => !LEGACY_SHELLS.includes(shell.value) || shell.value === current);
}
