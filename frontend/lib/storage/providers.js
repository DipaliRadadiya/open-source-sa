// `provider` is a real API column; nothing is inferred from the endpoint. PRESETS is what the user
// picks (several are `s3` to the backend); FIELDS, keyed by backend provider, says which inputs to render.

/** Input kinds the renderer knows how to draw. */
export const TEXT = "text";
export const SECRET = "secret";
export const NUMBER = "number";
export const TOGGLE = "toggle";
export const TEXTAREA = "textarea";

// `endpointHint` is never auto-filled: each contains a part only the account owner knows.
export const PRESETS = [
  { value: "aws", provider: "s3", endpointHint: "" },
  { value: "r2", provider: "s3", endpointHint: "https://<account-id>.r2.cloudflarestorage.com" },
  { value: "b2", provider: "s3", endpointHint: "https://s3.<region>.backblazeb2.com" },
  { value: "wasabi", provider: "s3", endpointHint: "https://s3.<region>.wasabisys.com" },
  { value: "spaces", provider: "s3", endpointHint: "https://<region>.digitaloceanspaces.com" },
  { value: "other", provider: "s3", endpointHint: "" },
  { value: "ftp", provider: "ftp" },
  { value: "sftp", provider: "sftp" },
  // Hidden from the picker but kept resolvable, or existing destinations get the S3 "other" edit form.
  { value: "google_drive", provider: "google_drive", legacy: true },
  // Drive accessed as the user (OAuth): the only way a free Gmail account can
  // use Drive.
  { value: "google_drive_oauth", provider: "google_drive_oauth" },
];

// Null where there is nowhere to send anyone ("Other", FTP).
const KEY_DOCS = {
  aws: "https://docs.aws.amazon.com/IAM/latest/UserGuide/id_credentials_access-keys.html",
  r2: "https://developers.cloudflare.com/r2/api/tokens/",
  b2: "https://www.backblaze.com/docs/cloud-storage-application-keys",
  wasabi: "https://docs.wasabi.com/v1/docs/create-a-user-and-access-key",
  spaces: "https://docs.digitalocean.com/products/spaces/how-to/manage-access/",
  // Unused by the form (`GoogleDriveSetup` links each Console page per step);
  // kept so the entry exists.
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

// `s3` deliberately resolves to the generic "other" preset rather than guessing from the endpoint.
export function presetForProvider(provider) {
  if (provider === "s3") return "other";
  return PRESETS.find((p) => p.provider === provider)?.value ?? "other";
}

// `name` is the key inside the API's `config`. `mono` marks addresses and keys, where a proportional font hides l/1.
export const FIELDS = {
  s3: [
    { name: "bucket", kind: TEXT, required: true, mono: true },
    // Required only for AWS, whose bucket host carries the region. Every
    // other S3 service takes it from the endpoint.
    { name: "region", kind: TEXT, mono: true, requiredForPresets: ["aws"] },
    // The inverse: blank *means* AWS, so everyone else must supply one.
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
    // Default on; off sends credentials and backups in the clear, so the
    // renderer warns.
    { name: "ssl", kind: TOGGLE, default: true, warnWhenOff: "plainFtpWarning" },
    { name: "passive", kind: TOGGLE, default: true },
  ],
  google_drive_oauth: [
    // No folder id: the `drive.file` scope only sees files this app made, so the panel creates its own.
    // The refresh token is written by the connect flow and never shown.
    { name: "client_id", kind: TEXT, required: true, mono: true },
    { name: "client_secret", kind: SECRET, required: true, mono: true },
  ],
  google_drive: [
    { name: "service_account_json", kind: TEXTAREA, required: true, mono: true },
    { name: "folder_id", kind: TEXT, required: true, mono: true },
  ],
  sftp: [
    { name: "host", kind: TEXT, required: true, mono: true },
    { name: "port", kind: NUMBER, placeholder: "22" },
    { name: "username", kind: TEXT, required: true, mono: true },
    // One of the two is required (the schema enforces the pair), so neither is
    // marked required on its own.
    { name: "password", kind: SECRET, oneOf: "auth" },
    { name: "private_key", kind: TEXTAREA, oneOf: "auth", mono: true },
    { name: "passphrase", kind: SECRET },
    { name: "root", kind: TEXT, mono: true },
  ],
};

// Keyed by preset first, provider second. Empty at present; kept for the next provider that needs one.
const WARNINGS = {
  presets: {},
  providers: {},
};

export function warningFor(preset) {
  return WARNINGS.presets[preset] ?? WARNINGS.providers[providerForPreset(preset)] ?? null;
}

export function fieldsFor(provider) {
  return FIELDS[provider] ?? [];
}

/** The credential fields for a provider — what a rotation dialog asks for. */
export function secretFieldsFor(provider) {
  return fieldsFor(provider).filter((f) => f.kind === SECRET || f.kind === TEXTAREA);
}

// Two S3 fields depend on the service picked.
export function isRequired(field, preset) {
  if (field.required) return true;
  if (field.requiredForPresets) return field.requiredForPresets.includes(preset);
  if (field.requiredUnlessPresets) return !field.requiredUnlessPresets.includes(preset);
  return false;
}

// Returned as strings so the row keeps control of layout.
export function describeDestination(destination) {
  const config = destination?.config ?? {};
  const prefix = destination?.prefix ?? "";

  if (destination?.provider === "google_drive_oauth") {
    // The account, not the folder: the folder is the panel's own.
    return {
      location: prefix || null,
      address: config.account_email || null,
    };
  }

  if (destination?.provider === "google_drive") {
    // The Shared Drive's name if a probe recorded one, else the folder id.
    return {
      location: [config.drive_name || config.folder_id, prefix].filter(Boolean).join("/"),
      address: config.client_email || null,
    };
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

// So a create form starts as the backend would save it. Other fields start as "", since a missing key
// would mount the input uncontrolled; `cleanConfig` strips the blanks.
export function defaultConfig(provider) {
  return Object.fromEntries(
    fieldsFor(provider).map((f) => [f.name, f.default !== undefined ? f.default : ""]),
  );
}
