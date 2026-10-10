// Only synthetic values; never asks a real server for credentials.
export const getContainerSecrets = (id) => globalThis.__firstRunApi.secrets(id);
export const acknowledgeContainerSecrets = (id) => globalThis.__firstRunApi.acknowledge(id);
