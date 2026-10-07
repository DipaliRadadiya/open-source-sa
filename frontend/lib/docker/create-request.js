import { ENV_KEY_PATTERN } from "../schemas/docker.js";
import { dockerCreateExtras } from "./image-ref.js";

// The backend's caps (DockerSiteType rules); past these the request is a 422.
export const DOCKER_LIMITS = {
  image: 255,
  envKey: 255,
  envValue: 65535,
  envRows: 100,
  volumes: 20,
};

/** Why one settings row cannot be sent, or null. */
export function envRowProblem(row) {
  const key = String(row?.key ?? "");
  const value = String(row?.value ?? "");
  if (row?.required && !value.trim()) return "required";
  if (key && (!ENV_KEY_PATTERN.test(key) || key.length > DOCKER_LIMITS.envKey)) return "badKey";
  if (!key && value.trim()) return "badKey";
  if (value.length > DOCKER_LIMITS.envValue) return "valueTooLong";
  return null;
}

/**
 * True while the settings would deploy a broken app. `requiredKeys` is what the
 * image demanded when it was read, so a required setting that is no longer in
 * the list still holds the form.
 */
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

/**
 * The volume and env part of the create request, plus which form row each sent
 * item came from so a 422 on `env.2.key` can be shown beside its row.
 */
export function dockerCreateFields({ applicationName, volumes = [], envRows = [] }) {
  // Only what differs from the image: its own defaults already apply.
  const changed = (envRows ?? []).filter(
    (row) => !row.fromImage || row.required || row.value !== row.original,
  );
  const sentEnvRows = changed.filter((row) => String(row.key ?? "").trim());
  const { mounts, env } = dockerCreateExtras({ applicationName, volumes, env: changed });
  const fields = {};
  // One volume uses the original pair, which every backend version accepts. Any
  // other count is an explicit list: an empty one means "no volumes", where
  // sending nothing would let the backend add the image's own.
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

/**
 * Splits a 422's errors into the ones the picker shows beside a row and the rest.
 * `set` is a list of [form error name, message]; `rest` keeps the original shape.
 */
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
