import { localStorageSettings } from "./local-settings.js";

export const TEXT = "text";
export const SECRET = "secret";
export const NUMBER = "number";
export const TOGGLE = "toggle";
export const TEXTAREA = "textarea";

export const PRESETS = [
  { value: "aws", provider: "s3", endpointHint: "" },
  { value: "r2", provider: "s3", endpointHint: "https://<account-id>.r2.cloudflarestorage.com" },
  { value: "b2", provider: "s3", endpointHint: "https://s3.<region>.backblazeb2.com" },
  { value: "wasabi", provider: "s3", endpointHint: "https://s3.<region>.wasabisys.com" },
  { value: "spaces", provider: "s3", endpointHint: "https://<region>.digitaloceanspaces.com" },
  { value: "other", provider: "s3", endpointHint: "" },
  { value: "ftp", provider: "ftp" },
  { value: "sftp", provider: "sftp" },
  { value: "google_drive", provider: "google_drive", legacy: true },
  { value: "google_drive_oauth", provider: "google_drive_oauth" },
  { value: "local", provider: "local" },
];

const KEY_DOCS = {
  aws: "https://docs.aws.amazon.com/IAM/latest/UserGuide/id_credentials_access-keys.html",
  r2: "https://developers.cloudflare.com/r2/api/tokens/",
  b2: "https://www.backblaze.com/docs/cloud-storage-application-keys",
  wasabi: "https://docs.wasabi.com/docs/creating-a-new-access-key",
  spaces: "https://docs.digitalocean.com/products/spaces/how-to/manage-access/",
  google_drive_oauth: "https://console.cloud.google.com/apis/credentials",
};

export function keyDocsUrl(preset) {
  return KEY_DOCS[preset] ?? null;
}

export function presetFor(value) {
  return PRESETS.find((p) => p.value === value) ?? null;
}

export function providerForPreset(value) {
  return presetFor(value)?.provider ?? "s3";
}

const S3_HOSTS = [
  ["aws", /(^|\.)amazonaws\.com$/],
  ["r2", /\.r2\.cloudflarestorage\.com$/],
  ["b2", /(^|\.)backblazeb2\.com$/],
  ["wasabi", /(^|\.)wasabisys\.com$/],
  ["spaces", /(^|\.)digitaloceanspaces\.com$/],
];
export function s3ServiceFor(destination) {
  if (destination?.provider !== "s3") return null;
  const endpoint = (destination.config?.endpoint ?? "").trim();
  if (!endpoint) return "aws";
  let host;
  try {
    host = new URL(endpoint).hostname.toLowerCase();
  } catch {
    return null;
  }
  return S3_HOSTS.find(([, pattern]) => pattern.test(host))?.[0] ?? null;
}

export function presetForProvider(provider) {
  if (provider === "s3") return "other";
  return PRESETS.find((p) => p.provider === provider)?.value ?? "other";
}

export const FIELDS = {
  s3: [
    { name: "bucket", kind: TEXT, required: true, mono: true },
    { name: "region", kind: TEXT, mono: true, requiredForPresets: ["aws"] },
    { name: "endpoint", kind: TEXT, mono: true, requiredUnlessPresets: ["aws"], hintsEndpoint: true },
    { name: "access_key", kind: SECRET, required: true, mono: true },
    { name: "secret_key", kind: SECRET, required: true, mono: true },
  ],
  ftp: [
    { name: "host", kind: TEXT, required: true, mono: true },
    { name: "port", kind: NUMBER, placeholder: "21" },
    { name: "username", kind: TEXT, required: true, mono: true },
    { name: "password", kind: SECRET, required: true },
    { name: "root", kind: TEXT, mono: true },
    { name: "ssl", kind: TOGGLE, default: true, warnWhenOff: "plainFtpWarning" },
    { name: "passive", kind: TOGGLE, default: true },
  ],
  google_drive_oauth: [
    // drive.file only sees files this app created, so the panel makes its own folder.
    { name: "client_id", kind: TEXT, required: true, mono: true },
    { name: "client_secret", kind: SECRET, required: true, mono: true },
  ],
  google_drive: [
    { name: "service_account_json", kind: TEXTAREA, required: true, mono: true },
    { name: "folder_id", kind: TEXT, required: true, mono: true },
  ],
  local: [{ name: "path", kind: TEXT, required: true, mono: true, changeWarning: "localPathChangeWarning" }],
  sftp: [
    { name: "host", kind: TEXT, required: true, mono: true },
    { name: "port", kind: NUMBER, placeholder: "22" },
    { name: "username", kind: TEXT, required: true, mono: true },
    { name: "password", kind: SECRET, oneOf: "auth" },
    { name: "private_key", kind: TEXTAREA, oneOf: "auth", mono: true },
    { name: "passphrase", kind: SECRET },
    { name: "root", kind: TEXT, mono: true },
  ],
};

const WARNINGS = {
  presets: {},
  providers: { local: "localWarning" },
};

export function hasCredentials(provider) {
  return provider !== "local";
}

export function warningFor(preset) {
  return WARNINGS.presets[preset] ?? WARNINGS.providers[providerForPreset(preset)] ?? null;
}

export function fieldsFor(provider) {
  return FIELDS[provider] ?? [];
}

export function secretFieldsFor(provider) {
  return fieldsFor(provider).filter((f) => f.kind === SECRET || f.kind === TEXTAREA);
}

export function isRequired(field, preset) {
  if (field.required) return true;
  if (field.requiredForPresets) return field.requiredForPresets.includes(preset);
  if (field.requiredUnlessPresets) return !field.requiredUnlessPresets.includes(preset);
  return false;
}

export function describeDestination(destination) {
  const config = destination?.config ?? {};
  const prefix = destination?.prefix ?? "";

  if (destination?.provider === "google_drive_oauth") {
    return {
      location: prefix || null,
      address: config.account_email || null,
    };
  }

  if (destination?.provider === "google_drive") {
    return {
      location: [config.drive_name || config.folder_id, prefix].filter(Boolean).join("/"),
      address: config.client_email || null,
    };
  }

  if (destination?.provider === "local") {
    return { location: [config.path, prefix].filter(Boolean).join("/"), address: null };
  }

  if (destination?.provider === "s3") {
    return {
      location: [config.bucket, prefix].filter(Boolean).join("/"),
      address: config.endpoint || null,
    };
  }

  const port = config.port ? `:${config.port}` : "";

  return {
    location: [config.root, prefix].filter(Boolean).join("/"),
    address: config.host ? `${config.username ? `${config.username}@` : ""}${config.host}${port}` : null,
  };
}

export function defaultConfig(provider, localSettings = null) {
  return Object.fromEntries(
    fieldsFor(provider).map((f) => [f.name, provider === "local" && f.name === "path" ? localStorageSettings(localSettings).default_path : f.default !== undefined ? f.default : ""]),
  );
}
