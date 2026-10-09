// Severe (red confirm): passwordOff and rootOff risk lockout, rootPassword widens root's
// attack surface. `port` is safe: the panel opens the new port in the firewall first.
// `enforcesAccess`: the first save writes AllowGroups, so users whose SSH switch is off lose SSH.
export const SSH_RISKS = ["port", "passwordOff", "rootOff", "rootPassword", "enforcesAccess"];

export const SEVERE_SSH_RISKS = ["passwordOff", "rootOff", "rootPassword"];

export function isSevereSshChange(risks = []) {
  return risks.some((risk) => SEVERE_SSH_RISKS.includes(risk));
}
