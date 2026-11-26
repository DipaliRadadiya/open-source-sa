import { z } from "zod";

const nullableString = z.string().nullable().optional();
const nullableNumber = z.number().nullable().optional();

export const serverFactsSchema = z.object({
  hostname: nullableString,
  os: nullableString,
  kernel: nullableString,
  arch: nullableString,
  uptime: z
    .object({ seconds: nullableNumber, human: nullableString })
    .nullable()
    .optional(),
  ip: nullableString,
  // The public address (what DNS points at). `ip` is the interface address,
  // often private on cloud hosts. Null when undeterminable (e.g. behind NAT).
  public_ip: nullableString,
  cpu: z
    .object({ model: nullableString, cores: nullableNumber })
    .nullable()
    .optional(),
  memory_total: nullableNumber,
  memory_total_human: nullableString,
  disk_total: nullableNumber,
  disk_total_human: nullableString,
  timezone: nullableString,
  reboot_required: z.boolean().nullable().optional(),
  runtimes: z.record(z.string(), nullableString).nullable().optional(),
});

export const historyPointSchema = z.object({
  sampled_at: nullableString,
  cpu: nullableNumber,
  memory: nullableNumber,
  swap: nullableNumber,
  disk: nullableNumber,
  load_1: nullableNumber,
  load_5: nullableNumber,
  load_15: nullableNumber,
  net_in: nullableNumber,
  net_out: nullableNumber,
  // Bytes/second. IOPS is live-only, so history charts show throughput only.
  disk_read: nullableNumber,
  disk_write: nullableNumber,
});

export const processSchema = z.object({
  pid: z.union([z.number(), z.string()]),
  user: nullableString,
  cpu: nullableNumber,
  memory: nullableNumber,
  command: nullableString,
  // False for PID 1, kernel threads and protected services; `reason` says why.
  stoppable: z.boolean().default(true),
  reason: nullableString,
});
