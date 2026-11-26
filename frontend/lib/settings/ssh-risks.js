// The first save writes AllowGroups, so users without SSH access get locked out.
export const SSH_RISKS = ["port", "passwordOff", "rootOff", "rootPassword", "enforcesAccess"];

export const SEVERE_SSH_RISKS = ["passwordOff", "rootOff", "rootPassword"];

export function isSevereSshChange(risks = []) {
  return risks.some((risk) => SEVERE_SSH_RISKS.includes(risk));
}
