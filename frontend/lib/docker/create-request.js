import { ENV_KEY_PATTERN } from "../schemas/docker.js";
import { dockerCreateExtras } from "./image-ref.js";

export function setDockerSource(form, source) {
  form.setValue("docker_source", source, { shouldDirty: true });
  form.setValue("docker_mode", source === "compose" ? "compose" : "simple", { shouldDirty: true });
}

export const DOCKER_LIMITS = {
  image: 255,
  envKey: 255,
  envValue: 65535,
  envRows: 100,
  volumes: 20,
};

export function envRowProblem(row) {
  const key = String(row?.key ?? "");
  const value = String(row?.value ?? "");
  if (row?.required && !value.trim()) return "required";
  if (key && (!ENV_KEY_PATTERN.test(key) || key.length > DOCKER_LIMITS.envKey)) return "badKey";
  if (!key && value.trim()) return "badKey";
  if (value.length > DOCKER_LIMITS.envValue) return "valueTooLong";
  return null;
}

export function dockerEnvProblem(rows = [], requiredKeys = []) {
  const list = rows ?? [];
  if (list.length > DOCKER_LIMITS.envRows) return true;
  if (list.some((row) => envRowProblem(row))) return true;
  const present = new Set(list.map((row) => String(row.key ?? "").trim()));
  return (requiredKeys ?? []).some((key) => !present.has(key));
}

export function dockerVolumeProblem(volumes = []) {
  return (volumes ?? []).filter((volume) => volume.checked).length > DOCKER_LIMITS.volumes;
}

export function dockerCreateFields({ applicationName, volumes = [], envRows = [] }) {
  const changed = (envRows ?? []).filter(
    (row) => !row.fromImage || row.required || row.value !== row.original,
  );
  const sentEnvRows = changed.filter((row) => String(row.key ?? "").trim());
  const { mounts, env } = dockerCreateExtras({ applicationName, volumes, env: changed });
  const fields = {};
  if (mounts.length === 1) {
    fields.volume_new = mounts[0].volume;
    fields.volume_path = mounts[0].path;
  } else {
    fields.volume_mounts = mounts;
  }
  if (env.length) fields.env = env;
  return {
    fields,
    sentEnvIds: sentEnvRows.map((row) => row.id),
    sentVolumePaths: mounts.map((mount) => mount.path),
  };
}

const ENV_ERROR = /^env(?:\.(\d+)(?:\.(key|value))?)?$/;
const VOLUME_ERROR = /^volume_mounts(?:\.(\d+)(?:\.\w+)?)?$/;

export function dockerServerErrors(errors = {}, { sentEnvIds = [], sentVolumePaths = [], envRows = [], volumes = [] } = {}) {
  const set = [];
  const rest = {};
  const rowFor = (id) => (envRows ?? []).findIndex((row) => row.id === id);
  const volumeFor = (path) => (volumes ?? []).findIndex((volume) => volume.path === path);

  for (const [name, messages] of Object.entries(errors ?? {})) {
    const message = Array.isArray(messages) ? messages[0] : messages;
    const env = name.match(ENV_ERROR);
    const volume = name.match(VOLUME_ERROR);
    if (env) {
      const row = env[1] === undefined ? -1 : rowFor(sentEnvIds[Number(env[1])]);
      set.push(row >= 0 ? [`docker_env.${row}.${env[2] ?? "value"}`, message] : ["docker_env_list", message]);
    } else if (volume || name === "volume_new" || name === "volume_path") {
      const index = volume ? (volume[1] === undefined ? -1 : Number(volume[1])) : 0;
      const row = index >= 0 ? volumeFor(sentVolumePaths[index]) : -1;
      set.push(row >= 0 ? [`docker_volumes.${row}`, message] : ["docker_volumes_list", message]);
    } else {
      rest[name] = messages;
    }
  }
  return { set, rest };
}
