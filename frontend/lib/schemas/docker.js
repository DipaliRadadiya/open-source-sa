import { z } from "zod";
// Relative: node tests import this file without the `@/` alias.
import { passwordField, sshKeySchema } from "./system-user.js";
import { DOCKER_CPU_PATTERN, DOCKER_MEMORY_PATTERN } from "../applications/docker-limit-rules.js";

// `built_in`: Docker's own `bridge`, `host` and `none`, shown with no actions.
export const dockerNetworkSchema = z.object({
  id: z.string(),
  name: z.string(),
  driver: z.string(),
  scope: z.string().nullish(),
  internal: z.boolean().nullish(),
  built_in: z.boolean(),
  // The site whose Compose project owns it (`<project>_default`).
  application_id: z.number().nullish(),
  // The sites that chose this network. Zod drops undeclared keys, so it must be listed.
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
        // As Docker renders them: `127.0.0.1:2368->2368/tcp` (published) or `3306/tcp`.
        ports: z.array(z.string()).default([]),
        // Reachable from the host (published), not just the network; decided server-side.
        published: z.boolean().default(false),
      }),
    )
    .default([]),
});

export const dockerVolumeSchema = z.object({
  name: z.string(),
  driver: z.string(),
  mountpoint: z.string().nullish(),
  // As Docker renders it ("5.243MB"); only ever displayed.
  size: z.string().nullish(),
  containers: z.number().default(0),
  // The names behind that count. Can be shorter than `containers` (different sources):
  // empty beside a non-zero count means "could not ask", not "unused".
  container_names: z.array(z.string()).default([]),
  // The sites configured to mount it, running or not (a stopped site has no container).
  sites: z.array(z.object({ id: z.number(), name: z.string() })).default([]),
  in_use: z.boolean(),
  dangling: z.boolean(),
  application_id: z.number().nullish(),
});

// A stored registry credential without the token (the API only says whether one exists).
// `auth_key` is the address Docker keys it on (Docker Hub: the legacy v1 index URL).
export const registrySchema = z.object({
  id: z.number(),
  name: z.string(),
  registry: z.string(),
  auth_key: z.string(),
  is_docker_hub: z.boolean().default(false),
  username: z.string(),
  has_credentials: z.boolean().default(false),
  // Only present when the API counted it.
  applications_count: z.number().nullish(),
  last_tested_at: z.string().nullish(),
  last_tested_at_human: z.string().nullish(),
  last_test_success: z.boolean().nullish(),
  last_test_error: z.string().nullish(),
  // `never_tested` is not `failed`, so this is not a boolean.
  status: z.enum(["connected", "failed", "never_tested"]),
  status_title: z.string(),
});

export const registriesResponseSchema = z.object({
  registries: z.array(registrySchema),
});

// The create form. `registry` mirrors the server's `RegistryHost`: Docker silently ignores an
// address it can't use (e.g. `ghcr.io/my-org`), so it is refused here.
export const registryFormSchema = z.object({
  name: z.string().min(1).max(100),
  registry: z
    .string()
    .min(1)
    .max(255)
    // host[:port] with an optional scheme; no path (namespace) and no `@`.
    .regex(
      /^(https?:\/\/)?[A-Za-z0-9]([A-Za-z0-9._-]*[A-Za-z0-9])?(:\d{1,5})?\/?$/,
    ),
  username: z.string().min(1).max(255),
  // No whitespace: a pasted newline would fail to authenticate unseen.
  token: z
    .string()
    .min(1)
    .max(4096)
    .refine((value) => !/\s/.test(value)),
});

// `generated`: rendered from the site's fields (no stored file); saving it makes the file
// hand-managed, so the UI warns before the first save.
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

// The server's core count (the CPU ceiling) and memory defaults, for the limit fields' hints:
// an empty memory field means the default, an empty CPU field means no limit.
export const dockerLimitsResponseSchema = z.object({
  limits: z.object({
    cpus: z.number().int().min(1),
    default_memory_limit: z.string(),
    default_db_memory_limit: z.string(),
  }),
});

// A name Docker accepts; mirrors the server's `DockerResources::validName()`.
export const dockerNameSchema = z
  .string()
  .min(1, "requiredField")
  .max(128, "dockerNameLength")
  .regex(/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/, "dockerName");

// The container settings form. An empty `docker_network` means the site's own network.
export const containerSettingsFormSchema = z.object({
  container_port: z.coerce.number("portRange").int("portRange").min(1, "portRange").max(65535, "portRange"),
  // The id as a string, or a sentinel for "pull anonymously" (Radix reserves `""`).
  registry_id: z.string(),
  memory_limit: z
    .string()
    .regex(DOCKER_MEMORY_PATTERN, "dockerMemory")
    .or(z.literal("")),
  // Cores, up to two decimals; empty means no quota. The host's core count is checked server-side.
  cpu_limit: z
    .string()
    .regex(DOCKER_CPU_PATTERN, "dockerCpu")
    .or(z.literal("")),
  docker_network: z.string(),
});

// One volume mounted at a path inside the container. The server also refuses `/`, `/app`
// and the image's own directories, as field errors.
export const volumeMountSchema = z.object({
  volume: dockerNameSchema,
  path: z
    .string()
    .min(1)
    .max(255)
    .regex(/^\//)
    .refine((path) => !/(^|\/)\.\.(\/|$)/.test(path)),
});

/**
 * Image discovery responses (DS-02 contract). Lenient on the optional parts:
 * one missing count must not reject a whole result list.
 */
export const imageSearchResponseSchema = z.object({
  results: z
    .array(
      z.object({
        image: z.string(),
        registry: z.string().nullish(),
        description: z.string().nullish(),
        stars: z.number().nullish(),
        pulls: z.number().nullish(),
        official: z.boolean().default(false),
        verified_publisher: z.boolean().default(false),
      }),
    )
    .default([]),
  offline: z.boolean().default(false),
});

export const imageTagsResponseSchema = z.object({
  image: z.string().nullish(),
  recommended: z.string().nullish(),
  tags: z
    .array(
      z.object({
        name: z.string(),
        updated_at: z.string().nullish(),
        stable: z.boolean().default(false),
        // -ea, -rc, -beta…: never `recommended`, which is null when only these exist.
        prerelease: z.boolean().default(false),
      }),
    )
    .default([]),
});

export const imageInspectResponseSchema = z.object({
  image: z.string().nullish(),
  found: z.boolean(),
  message: z.string().nullish(),
  exposed_ports: z.array(z.number()).default([]),
  suggested_port: z.number().int().nullish(),
  port_confidence: z.enum(["declared", "guessed", "none"]).catch("none"),
  volumes: z.array(z.string()).default([]),
  suggested_volumes: z
    .array(z.object({ path: z.string(), name: z.string().nullish() }))
    .default([]),
  env: z
    .array(
      z.object({
        key: z.string(),
        default: z.string().nullish(),
        required: z.boolean().default(false),
      }),
    )
    .default([]),
  size_bytes: z.number().nullish(),
  architectures: z.array(z.string()).default([]),
  uses_app_dir: z.boolean().default(false),
  warnings: z.array(z.string()).default([]),
  // One language-free code per `warnings` entry, same order (RC-32); absent on older backends.
  warning_codes: z.array(z.string()).default([]),
});

/** Matches DS-03's create rule for env keys. */
export const ENV_KEY_PATTERN = /^[A-Za-z_][A-Za-z0-9_]*$/;

/**
 * `GET /applications/{id}/container/access` (RC-23, RC-28): the app's own system
 * user, whether its SSH login opens the container, and the exact lines to copy.
 * POST and DELETE on the same path turn that container login on and off.
 */
export const containerAccessSchema = z.object({
  // What `@<app>` picks when the owner has several Docker apps.
  app: z.string().min(1),
  owner: z.object({
    username: z.string().min(1),
    ssh_access: z.boolean().default(false),
    container_login: z.boolean().default(false),
    has_password: z.boolean().default(false),
    ssh_keys: z.number().int().nonnegative().default(0),
    // Non-Docker apps: their SSH and SFTP stop once container login is on.
    other_apps: z.number().int().nonnegative().default(0),
  }),
  // Null when the server could not read sshd's setting: neither on nor off.
  password_login: z.boolean().nullish(),
  commands: z
    .object({
      shell: z.string().min(1),
      exec_example: z.string().min(1),
      logs: z.string().min(1),
    })
    .nullish(),
  cli_examples: z
    .array(z.object({ label: z.string().min(1), command: z.string().min(1) }))
    .default([]),
  host: z.string().min(1),
  ssh_port: z.number().int().nullish(),
  containers: z
    .array(
      z.object({
        service: z.string(),
        name: z.string().min(1),
        main: z.boolean().default(false),
      }),
    )
    .min(1),
});

/**
 * The turn-on step. Key and password go through the System Users rules; which
 * one is required, and whether the container-only switch must be confirmed,
 * depends on the owner and on sshd (`turnOnNeeds`).
 */
export function containerLoginSchema({ needs = "nothing", confirm = false } = {}) {
  return z
    .object({
      ssh_key: z.union([z.literal(""), sshKeySchema.shape.public_key]),
      password: z.union([z.literal(""), passwordField]),
      confirm_container_only: z.boolean(),
    })
    .superRefine((values, ctx) => {
      if (needs === "key" && !values.ssh_key) {
        ctx.addIssue({ code: "custom", path: ["ssh_key"], message: "sshKeyRequired" });
      }
      if (needs === "keyOrPassword" && !values.ssh_key && !values.password) {
        ctx.addIssue({ code: "custom", path: ["ssh_key"], message: "sshKeyOrPassword" });
      }
      if (confirm && !values.confirm_container_only) {
        ctx.addIssue({ code: "custom", path: ["confirm_container_only"], message: "confirmContainerOnly" });
      }
    });
}
