/**
 * Which SSH changes get a red confirm button.
 *
 *   port          Safe: the panel opens the new port in the firewall first.
 *   passwordOff   Lockout risk without a key on the server.
 *   rootOff       Lockout risk without another sudo user.
 *   rootPassword  Widens the attack surface on root.
 *
 * Kept out of the component so the classification can be tested.
 */
export const SSH_RISKS = ["port", "passwordOff", "rootOff", "rootPassword"];

export const SEVERE_SSH_RISKS = ["passwordOff", "rootOff", "rootPassword"];

export function isSevereSshChange(risks = []) {
  return risks.some((risk) => SEVERE_SSH_RISKS.includes(risk));
}
