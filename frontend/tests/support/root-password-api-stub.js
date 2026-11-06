// Component tests only: records each root-password call and answers from globalThis.__rootPassword.
export async function setRootPassword(engine, values) {
  (globalThis.__rootPasswordCalls ??= []).push({ engine, values });
  return globalThis.__rootPassword(engine, values);
}
