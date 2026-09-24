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
  containers: z.array(z.string()).default([]),
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
