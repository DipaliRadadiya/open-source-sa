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

// total/used/free/percent breakdown shared by memory, swap and disk.
const resourceSchema = z.object({
  total: nullableNumber,
  used: nullableNumber,
  free: nullableNumber,
  percent: nullableNumber,
  total_human: nullableString,
  used_human: nullableString,
  free_human: nullableString,
});

export const liveMetricsSchema = z.object({
  cpu: z.object({ percent: nullableNumber, cores: nullableNumber }),
  memory: resourceSchema,
  swap: resourceSchema,
  disk: resourceSchema,
  load: z.object({
    1: nullableNumber,
    5: nullableNumber,
    15: nullableNumber,
  }),
  network: z.object({
    in: nullableNumber,
    out: nullableNumber,
    in_human: nullableString,
    out_human: nullableString,
  }),
  // Throughput shows saturation; op counts show thrashing (a disk can be 100%
  // busy while moving few megabytes).
  disk_io: z
    .object({
      read: nullableNumber,
      write: nullableNumber,
      read_human: nullableString,
      write_human: nullableString,
      read_ops: nullableNumber,
      write_ops: nullableNumber,
    })
    .nullable()
    .optional(),
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
});
