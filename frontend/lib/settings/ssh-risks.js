/**
 * Which SSH changes are worth a red confirm button.
 *
 * The confirm dialog on the SSH form is not one dialog: it lists between one
 * and four consequences depending on what actually changed, so a fixed button
 * colour is wrong in one direction or the other.
 *
 *   port          SSH moves to another port. The panel opens the new port in
 *                 the firewall BEFORE applying, so this one is genuinely safe
 *                 and reddening it cries wolf.
 *   passwordOff   password logins stop working — with no key already on the
 *                 server, that is a locked door with the key inside.
 *   rootOff       root can no longer log in. Fine if another sudo user exists,
 *                 a lockout if not, and the panel cannot tell which.
 *   rootPassword  root becomes reachable by password. The odd one out: it
 *                 enables rather than removes, so "destructive" is the wrong
 *                 word — but it widens the attack surface on the account
 *                 attacks try first, and red is the only tone this design
 *                 system has for "stop and think".
 *
 * Kept out of the component so the classification can be tested, and so a new
 * consequence cannot quietly default to the reassuring colour.
 */
export const SSH_RISKS = ["port", "passwordOff", "rootOff", "rootPassword"];

export const SEVERE_SSH_RISKS = ["passwordOff", "rootOff", "rootPassword"];

export function isSevereSshChange(risks = []) {
  return risks.some((risk) => SEVERE_SSH_RISKS.includes(risk));
}
