import { z } from "zod";
import { passwordField, sshKeySchema } from "./system-user.js";
import { DOCKER_CPU_PATTERN, DOCKER_MEMORY_PATTERN } from "../applications/docker-limit-rules.js";

export const dockerNetworkSchema = z.object({
  id: z.string(),
  name: z.string(),
  driver: z.string(),
  scope: z.string().nullish(),
  internal: z.boolean().nullish(),
  built_in: z.boolean(),
  application_id: z.number().nullish(),
  // Must be declared or Zod strips it.
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
        ports: z.array(z.string()).default([]),
        published: z.boolean().default(false),
      }),
    )
    .default([]),
});

export const dockerVolumeSchema = z.object({
  name: z.string(),
  driver: z.string(),
  mountpoint: z.string().nullish(),
  size: z.string().nullish(),
  containers: z.number().default(0),
  container_names: z.array(z.string()).default([]),
  sites: z.array(z.object({ id: z.number(), name: z.string() })).default([]),
  in_use: z.boolean(),
  dangling: z.boolean(),
  application_id: z.number().nullish(),
});

export const registrySchema = z.object({
  id: z.number(),
  name: z.string(),
  registry: z.string(),
  auth_key: z.string(),
  is_docker_hub: z.boolean().default(false),
  username: z.string(),
  has_credentials: z.boolean().default(false),
  applications_count: z.number().nullish(),
  last_tested_at: z.string().nullish(),
  last_tested_at_human: z.string().nullish(),
  last_test_success: z.boolean().nullish(),
  last_test_error: z.string().nullish(),
  status: z.enum(["connected", "failed", "never_tested"]),
  status_title: z.string(),
});

export const registriesResponseSchema = z.object({
  registries: z.array(registrySchema),
});

// Docker silently ignores an address like ghcr.io/my-org, so refuse it here.
export const registryFormSchema = z.object({
  name: z.string().min(1).max(100),
  registry: z
    .string()
    .min(1)
    .max(255)
    .regex(
      /^(https?:\/\/)?[A-Za-z0-9]([A-Za-z0-9._-]*[A-Za-z0-9])?(:\d{1,5})?\/?$/,
    ),
  username: z.string().min(1).max(255),
  token: z
    .string()
    .min(1)
    .max(4096)
    .refine((value) => !/\s/.test(value)),
});

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

export const dockerLimitsResponseSchema = z.object({
  limits: z.object({
    cpus: z.number().int().min(1),
    default_memory_limit: z.string(),
    default_db_memory_limit: z.string(),
  }),
});

export const dockerNameSchema = z
  .string()
  .min(1, "requiredField")
  .max(128, "dockerNameLength")
  .regex(/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/, "dockerName");

export const containerSettingsFormSchema = z.object({
  container_port: z.coerce.number("portRange").int("portRange").min(1, "portRange").max(65535, "portRange"),
  // Radix reserves "" for no selection, so anonymous pull needs its own value.
  registry_id: z.string(),
  memory_limit: z
    .string()
    .regex(DOCKER_MEMORY_PATTERN, "dockerMemory")
    .or(z.literal("")),
  cpu_limit: z
    .string()
    .regex(DOCKER_CPU_PATTERN, "dockerCpu")
    .or(z.literal("")),
  docker_network: z.string(),
});

export const volumeMountSchema = z.object({
  volume: dockerNameSchema,
  path: z
    .string()
    .min(1)
    .max(255)
    .regex(/^\//)
    .refine((path) => !/(^|\/)\.\.(\/|$)/.test(path)),
});

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
  warning_codes: z.array(z.string()).default([]),
});

export const ENV_KEY_PATTERN = /^[A-Za-z_][A-Za-z0-9_]*$/;

export const containerAccessSchema = z.object({
  app: z.string().min(1),
  owner: z.object({
    username: z.string().min(1),
    ssh_access: z.boolean().default(false),
    container_login: z.boolean().default(false),
    has_password: z.boolean().default(false),
    ssh_keys: z.number().int().nonnegative().default(0),
    other_apps: z.number().int().nonnegative().default(0),
  }),
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
