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
  sites: z.array(z.object({ id: z.number(), name: z.string() })).default([]),
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
  in_use: z.boolean(),
  dangling: z.boolean(),
  application_id: z.number().nullish(),
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
  memory_limit: z
    .string()
    .regex(/^\d+(b|k|m|g)?$/i)
    .or(z.literal("")),
  docker_network: z.string(),
});
