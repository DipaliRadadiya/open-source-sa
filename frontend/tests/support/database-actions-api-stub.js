// Component tests only: no requests or credentials leave the process.
export async function phpmyadminSso(...args) {
  return globalThis.__databaseActionsApi.phpmyadminSso(...args);
}

export async function adminerSso(...args) {
  return globalThis.__databaseActionsApi.adminerSso(...args);
}
