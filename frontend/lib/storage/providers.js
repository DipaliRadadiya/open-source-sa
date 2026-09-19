/**
 * What each storage provider needs, declared once.
 *
 * This replaces two files that existed only because the API had no provider
 * field: `provider-from-endpoint.js`, which guessed the provider back out of
 * the endpoint hostname, and `requirements.js`, which decided whether endpoint
 * and region were required because "the API cannot enforce this, it has no
 * idea which provider you picked". It does now — `provider` is a real column,
 * validated per provider on the way in — so the guessing is gone and this file
 * describes the form rather than compensating for the backend.
 *
 * Two layers, deliberately:
 *
 * - **PRESETS** is what the user picks from. Amazon S3, R2, Backblaze and
 *   Wasabi are not different providers to the backend — they are all `s3` with
 *   a different endpoint — but they are absolutely different things to the
 *   person filling the form, and collapsing them into one "S3-compatible"
 *   option would throw away the endpoint examples and the key-docs links that
 *   make the form fillable.
 * - **FIELDS** is keyed by the *backend* provider and says which inputs to
 *   render. One renderer reads it; nothing hand-rolls a per-provider form.
 */

/** Input kinds the renderer knows how to draw. */
export const TEXT = "text";
export const SECRET = "secret";
export const NUMBER = "number";
export const TOGGLE = "toggle";
export const TEXTAREA = "textarea";

/**
 * The list shown in the picker, in the order shown.
 *
 * `endpointHint` is a HINT, never an auto-filled value: every one of these
 * contains a part only the account owner knows (an account id, a region), so
 * filling the field with a template would hand the user a value that fails
 * validation — which is exactly what happened on the live panel while this
 * feature was first built.
 */
export const PRESETS = [
  { value: "aws", provider: "s3", endpointHint: "" },
  { value: "r2", provider: "s3", endpointHint: "https://<account-id>.r2.cloudflarestorage.com" },
  { value: "b2", provider: "s3", endpointHint: "https://s3.<region>.backblazeb2.com" },
  { value: "wasabi", provider: "s3", endpointHint: "https://s3.<region>.wasabisys.com" },
  { value: "spaces", provider: "s3", endpointHint: "https://<region>.digitaloceanspaces.com" },
  { value: "other", provider: "s3", endpointHint: "" },
  { value: "ftp", provider: "ftp" },
  { value: "sftp", provider: "sftp" },
  /*
   * Legacy: not offered for new destinations, but kept resolvable.
   *
   * OAuth replaced it — a service account has no quota of its own and could
   * only ever write to a Workspace Shared Drive, which excluded every free
   * Gmail account. Two entries both called Google Drive was the confusion this
   * removes.
   *
   * Deleting the row outright would have been worse than the confusion:
   * `presetForProvider` falls back to "other", which is an *S3* preset, so
   * editing an existing service-account destination would have rendered the S3
   * form. It stays here, hidden from the picker by `legacy`.
   */
  { value: "google_drive", provider: "google_drive", legacy: true },
  // The same service reached as the user rather than as a service account,
  // which is the only way a free Gmail account can use Drive at all.
  { value: "google_drive_oauth", provider: "google_drive_oauth" },
  // Plain WebDAV — Nextcloud, ownCloud, or anything else that speaks it.
  //
  // This was a `pcloud` preset until pCloud was withdrawn as an option. It is
  // renamed rather than deleted: `presetForProvider` falls back to "other",
  // which is an *S3* preset, so removing the only `webdav` entry would have
  // rendered the S3 form when editing an existing WebDAV destination. Exactly
  // the trap the legacy Drive row above documents.
  { value: "webdav", provider: "webdav" },
];

/**
 * Where a preset's credentials are created.
 *
 * Every provider calls them something different — "API token" at Cloudflare,
 * "Application Key" at Backblaze — so "paste your access key" sends a
 * first-time user hunting for a phrase that is not on the page. Null for the
 * ones with nowhere to send anybody: "Other" is whatever the reader is
 * running, and an FTP server's password came from whoever set it up.
 */
const KEY_DOCS = {
  aws: "https://docs.aws.amazon.com/IAM/latest/UserGuide/id_credentials_access-keys.html",
  r2: "https://developers.cloudflare.com/r2/api/tokens/",
  b2: "https://www.backblaze.com/docs/cloud-storage-application-keys",
  wasabi: "https://docs.wasabi.com/v1/docs/create-a-user-and-access-key",
  spaces: "https://docs.digitalocean.com/products/spaces/how-to/manage-access/",
  // Not a key to copy but a client to create. The setup is six steps in
  // Google Cloud Console, and the one nobody guesses is choosing the "TVs and
  // Limited Input devices" client type.
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

/**
 * The first preset that maps to a given backend provider.
 *
 * Used when editing: the destination knows it is `ftp`, and the form needs a
 * preset to render from. Unambiguous for every provider that has exactly one
 * preset — which `webdav` does, so editing a WebDAV destination shows the
 * WebDAV form rather than whichever preset happened to be first in the list. For `s3` this deliberately resolves to the generic
 * option rather than trying to work out *which* S3 service it is — that
 * inference is what this refactor deleted, and a rename should not start
 * claiming a destination is Backblaze because its endpoint looks like it.
 */
export function presetForProvider(provider) {
  if (provider === "s3") return "other";
  return PRESETS.find((p) => p.provider === provider)?.value ?? "other";
}

/**
 * Field definitions per backend provider.
 *
 * `name` is the key inside the `config` object the API expects. `required`
 * is a claim about this form, so the asterisk means something. `mono` marks
 * the values that are addresses or keys rather than prose, where a
 * proportional font makes an l/1 mix-up invisible.
 */
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
    // Default on. Turning it off sends the password and every backup in the
    // clear, so the renderer shows a warning beside it when it is off.
    { name: "ssl", kind: TOGGLE, default: true, warnWhenOff: "plainFtpWarning" },
    { name: "passive", kind: TOGGLE, default: true },
  ],
  webdav: [
    { name: "base_uri", kind: TEXT, required: true, mono: true },
    { name: "username", kind: TEXT, required: true, mono: true },
    { name: "password", kind: SECRET, required: true },
  ],
  google_drive_oauth: [
    // Two fields and no folder id. The panel creates its own folder, because
    // the `drive.file` scope can only see files this app made — so there is
    // nothing to browse for and nothing to paste.
    //
    // The refresh token is never a field: it is written by the connect flow
    // and never shown, so it appears nowhere in this list.
    { name: "client_id", kind: TEXT, required: true, mono: true },
    { name: "client_secret", kind: SECRET, required: true, mono: true },
  ],
  google_drive: [
    // One paste and one id. No OAuth means no client id, no secret, no refresh
    // token and no callback — by some distance the smallest credential set of
    // the four providers.
    { name: "service_account_json", kind: TEXTAREA, required: true, mono: true },
    { name: "folder_id", kind: TEXT, required: true, mono: true },
  ],
  sftp: [
    { name: "host", kind: TEXT, required: true, mono: true },
    { name: "port", kind: NUMBER, placeholder: "22" },
    { name: "username", kind: TEXT, required: true, mono: true },
    // Neither is required on its own — one of the two is. The schema enforces
    // that pair rule; marking either as required here would put an asterisk
    // on a field the user is entitled to leave empty.
    { name: "password", kind: SECRET, oneOf: "auth" },
    { name: "private_key", kind: TEXTAREA, oneOf: "auth", mono: true },
    { name: "passphrase", kind: SECRET },
    { name: "root", kind: TEXT, mono: true },
  ],
};

/**
 * A constraint worth stating before the form is filled in, keyed by preset
 * first and provider second.
 *
 * Generalised from what began as a hardcoded Google Drive branch in the
 * renderer. A second provider needing the same treatment is the moment a
 * special case should become a lookup — otherwise the third one gets forgotten.
 *
 * Empty at present, and kept rather than deleted because the shape is the
 * point: a destination that *cannot work* is not the same as one that will not
 * work *well*, and neither is discoverable by trying. Both previous entries
 * went when the things they warned about did — the Drive one because OAuth
 * removed the wall it signposted, the pCloud one because pCloud is no longer
 * offered.
 */
const WARNINGS = {
  presets: {},
  // The Drive warning is gone with the preset it belonged to. It told free
  // Gmail users to go elsewhere; OAuth means they no longer have to, so the
  // sign comes down because the wall did.
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

/**
 * Whether a field is required given the chosen preset.
 *
 * Kept as a function rather than baked into the definition because two of the
 * S3 fields flip based on which service was picked, and the rule reads more
 * honestly in one place than as two special cases in the renderer.
 */
export function isRequired(field, preset) {
  if (field.required) return true;
  if (field.requiredForPresets) return field.requiredForPresets.includes(preset);
  if (field.requiredUnlessPresets) return !field.requiredUnlessPresets.includes(preset);
  return false;
}

/**
 * The two lines a list row shows under the name: where this destination is,
 * and how it is addressed.
 *
 * Per-provider because "bucket/prefix" is meaningless for an FTP host and an
 * empty bucket column beside a hostname is worse than no column. Returned as
 * strings rather than rendered here so the row stays in charge of layout — and
 * so it never grows a column per provider, which is the shape this would take
 * if each one got its own field.
 */
export function describeDestination(destination) {
  const config = destination?.config ?? {};
  const prefix = destination?.prefix ?? "";

  if (destination?.provider === "webdav") {
    return {
      location: prefix || null,
      address: config.base_uri || null,
    };
  }

  if (destination?.provider === "google_drive_oauth") {
    // Whose Drive, not which folder. The folder is ours and was never chosen
    // by anyone, so naming it would answer a question nobody asked; the
    // account is the thing an operator needs to recognise months later.
    return {
      location: prefix || null,
      address: config.account_email || null,
    };
  }

  if (destination?.provider === "google_drive") {
    // The Shared Drive's name if a successful probe recorded one, falling back
    // to the folder id. An id alone tells the operator nothing about which of
    // their drives this is.
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

/**
 * The config defaults for a provider, so a create form starts in the state the
 * backend would apply anyway. Without this the FTP TLS toggle renders as off
 * on first paint and then saves as on, which tells the user the opposite of
 * what will happen.
 *
 * Every other field starts as an empty string rather than absent: the renderer
 * spreads the field straight onto the input, so a missing key makes React
 * mount it uncontrolled and adopt it on the first keystroke. `cleanConfig`
 * strips the blanks again before they are sent.
 */
export function defaultConfig(provider) {
  return Object.fromEntries(
    fieldsFor(provider).map((f) => [f.name, f.default !== undefined ? f.default : ""]),
  );
}
