import { z } from "zod";

/**
 * A Docker network as the panel presents it.
 *
 * `built_in` is the one field with teeth: `bridge`, `host` and `none` are
 * Docker's own, recreated on restart, and removing one breaks every container
 * on the server. The UI shows them and offers no action.
 */
export const dockerNetworkSchema = z.object({
  id: z.string(),
  name: z.string(),
  driver: z.string(),
  scope: z.string().nullish(),
  internal: z.boolean().nullish(),
  built_in: z.boolean(),
  // Compose names a project's network `<project>_default`, so the panel can
  // say which site owns it rather than showing a machine-generated name as
  // though a human chose it.
  application_id: z.number().nullish(),
  // The sites that CHOSE this network, which is a different question from the
  // one above: `application_id` is inferred from Compose's naming, and says
  // nothing about a site that joined a network somebody else created. Declared
  // here or it never arrives — Zod strips what the schema does not name, so an
  // unlisted key is silently dropped between the API and the page.
  // `path` is where the SITE mounts it inside its container. More useful than the
  // host mountpoint on its own: `/var/lib/mysql` says this volume is a database.
  sites: z
    .array(
      z.object({
        id: z.number(),
        name: z.string(),
        path: z.string().default(""),
      }),
    )
    .default([]),
  containers: z
    .array(
      z.object({
        name: z.string(),
        // As Docker renders them: `127.0.0.1:2368->2368/tcp` for a published
        // port, a bare `3306/tcp` for one only exposed to this network.
        ports: z.array(z.string()).default([]),
        // The arrow, decided server-side. An exposed port is reachable from
        // the same network; a published one is reachable from the host. Far
        // too easy to read as the same thing, so the API answers rather than
        // leaving the UI to parse a string.
        published: z.boolean().default(false),
      }),
    )
    .default([]),
});

export const dockerVolumeSchema = z.object({
  name: z.string(),
  driver: z.string(),
  mountpoint: z.string().nullish(),
  // As Docker renders it — "5.243MB". A string on purpose: re-parsing it into
  // bytes would be the panel guessing at a unit, and the only use for the
  // value is to be read.
  size: z.string().nullish(),
  containers: z.number().default(0),
  // The names behind that count. Declared here or Zod strips it — silently, and
  // looking exactly like an API that did not send it.
  //
  // Can be shorter than `containers`: the count comes from `system df -v` and
  // the names from `container inspect`, so an empty list beside a non-zero count
  // means "could not ask", not "nothing is using it". The cell says so.
  container_names: z.array(z.string()).default([]),
  // The sites configured to mount it, running or not. `container_names` cannot
  // answer this: a stopped site has no container, and its data is still in here.
  sites: z.array(z.object({ id: z.number(), name: z.string() })).default([]),
  in_use: z.boolean(),
  dangling: z.boolean(),
  application_id: z.number().nullish(),
});

/**
 * A stored registry credential, minus the credential.
 *
 * There is no `token` field and there is no reveal endpoint to add one — the API
 * reports only whether a token exists. `has_credentials` is therefore the whole of
 * what the UI can say about the secret.
 *
 * `auth_key` is worth surfacing rather than hiding: Docker Hub's credentials must
 * be keyed on the legacy v1 index URL, so a user who typed `docker.io` sees what
 * the panel will actually write instead of wondering why their entry looks wrong.
 */
export const registrySchema = z.object({
  id: z.number(),
  name: z.string(),
  registry: z.string(),
  auth_key: z.string(),
  is_docker_hub: z.boolean().default(false),
  username: z.string(),
  has_credentials: z.boolean().default(false),
  // Only present when the API counted it. Declared or Zod strips it — silently,
  // and looking exactly like an API that did not send it.
  applications_count: z.number().nullish(),
  last_tested_at: z.string().nullish(),
  last_tested_at_human: z.string().nullish(),
  last_test_success: z.boolean().nullish(),
  last_test_error: z.string().nullish(),
  // `never_tested` is a distinct state from `failed`, so this is not a boolean:
  // "we have not asked" is not the user's problem to fix.
  status: z.enum(["connected", "failed", "never_tested"]),
  status_title: z.string(),
});

export const registriesResponseSchema = z.object({
  registries: z.array(registrySchema),
});

/**
 * The create form.
 *
 * `registry` mirrors `RegistryHost` on the server, and the mirroring matters more
 * here than for most fields: an address Docker cannot interpret is silently
 * IGNORED at pull time — the credential never applies and the error is identical
 * to having none at all. So a namespace like `ghcr.io/my-org` has to be a message
 * under the field, not a discovery three screens later.
 */
export const registryFormSchema = z.object({
  name: z.string().min(1).max(100),
  registry: z
    .string()
    .min(1)
    .max(255)
    // host[:port], with an optional scheme this and the server both strip. No
    // slash, so a namespace is refused; no `@`, so a credential cannot hide here.
    .regex(
      /^(https?:\/\/)?[A-Za-z0-9]([A-Za-z0-9._-]*[A-Za-z0-9])?(:\d{1,5})?\/?$/,
    ),
  username: z.string().min(1).max(255),
  // A newline authenticates nowhere while looking correct — and this form shows
  // the value back in no screen, so there would be nothing to look at.
  token: z
    .string()
    .min(1)
    .max(4096)
    .refine((value) => !/\s/.test(value)),
});

/**
 * The compose file, as the editor reads it.
 *
 * `generated` is the load-bearing half: true means the site has no stored file and
 * this text was rendered from its fields, so saving it converts the site to a
 * hand-managed file and the fields stop driving it. The UI has to say that before
 * the first save, not after.
 */
export const composeFileResponseSchema = z.object({
  compose: z.string(),
  generated: z.boolean(),
});

export const dockerNetworksResponseSchema = z.object({
  networks: z.array(dockerNetworkSchema),
});

export const dockerVolumesResponseSchema = z.object({
  volumes: z.array(dockerVolumeSchema),
});

/**
 * A name Docker will accept.
 *
 * Mirrors `DockerResources::validName()` — `[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}`.
 * The server is still the authority (it is what hands the value to a command
 * line); this exists so a typo is a message under the field rather than a
 * round-trip and a toast in the corner.
 */
export const dockerNameSchema = z
  .string()
  .min(1)
  .max(128)
  .regex(/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/);

/**
 * The container settings form.
 *
 * `docker_network` is nullable and empty means Docker's default bridge — a real
 * answer, not a missing one, which is why it is not `.min(1)`.
 */
export const containerSettingsFormSchema = z.object({
  container_port: z.coerce.number().int().min(1).max(65535),
  // A sentinel string, not a number or a null: Radix reserves `""` for "nothing
  // selected", so "pull anonymously" needs a value of its own and the form holds
  // the id as a string. The card maps both back on submit.
  registry_id: z.string(),
  memory_limit: z
    .string()
    .regex(/^\d+(b|k|m|g)?$/i)
    .or(z.literal("")),
  docker_network: z.string(),
});

/**
 * One volume mounted into a container, at a path inside it.
 *
 * The path is the half people forget. A volume name on its own is not actionable
 * — `shop-db` is only useful at `/var/lib/mysql` — which is why "attach this
 * volume to a container" cannot be a single dropdown.
 *
 * The server refuses more than this does: `/app` (where the site's own files
 * live), `/`, and the image's own directories. Those need to know what the
 * compose file mounts, so they stay server-side and arrive as field errors.
 */
export const volumeMountSchema = z.object({
  volume: dockerNameSchema,
  path: z
    .string()
    .min(1)
    .max(255)
    .regex(/^\//)
    .refine((path) => !/(^|\/)\.\.(\/|$)/.test(path)),
});
