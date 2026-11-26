import { z } from "zod";
import { listMetaSchema } from "./list.js";

export const RESERVED_NAMES = [
  "mysql",
  "information_schema",
  "performance_schema",
  "sys",
  "admin",
  "local",
  "config",
  "postgres",
  "template0",
  "template1",
];

export function reservedNames(engines = []) {
  const published = engines.flatMap((engine) =>
    Array.isArray(engine?.system_schemas) ? engine.system_schemas : [],
  );
  const names = published.length ? published : RESERVED_NAMES;
  return new Set(names.map((name) => String(name).toLowerCase()));
}

export const DATABASE_NAME = /^[A-Za-z0-9_]{1,63}$/;

export const CONNECTION_PREFERENCES = ["localhost", "remote", "anywhere"];

export const databaseInstallProgressSchema = z.object({
  status: z.string(),
  started_at: z.string().nullish(),
  started_at_human: z.string().nullish(),
  reason: z.string().nullish(),
  message: z.string().nullish(),
  reference: z.string().nullish(),
  current_step: z.string().nullish(),
  current_step_title: z.string().nullish(),
  output: z.string().nullish(),
  retryable: z.boolean().optional(),
});

// Mongo sends [] instead of {}, which z.record rejects.
const charsetsSchema = z
  .union([z.record(z.string(), z.array(z.string())), z.array(z.unknown())])
  .nullable()
  .optional()
  .transform((value) => (value && !Array.isArray(value) ? value : {}));

export const engineSchema = z.object({
  engine: z.string(),
  driver: z.string().nullable().optional(),
  supports_remote_users: z.boolean().default(true),
  system_schemas: z.array(z.string()).nullish(),
  unavailable: z
    .object({ code: z.string(), reason: z.string() })
    .nullable()
    .optional(),
  running: z.boolean().nullable().optional().default(false),
  installed: z.boolean().nullable().optional().default(false),
  version: z.string().nullable().optional(),
  charsets: charsetsSchema,
  installable: z.boolean().nullable().optional().default(false),
  install_status: z.string().nullable().optional(),
  install_reason: z.string().nullable().optional(),
  install_message: z.string().nullable().optional(),
  install_progress: databaseInstallProgressSchema.nullish(),
});

export const enginesResponseSchema = z.object({
  engines: z.array(engineSchema).default([]),
});

export const databaseUserSchema = z.object({
  id: z.number(),
  database_id: z.number().nullable().optional(),
  username: z.string(),
  password: z.string().nullable().optional(),
  connection_preference: z.string().nullable().optional(),
  host: z.string().nullable().optional(),
  connection_string: z.string().nullable().optional(),
  connection: z
    .object({ host: z.string().nullish(), port: z.union([z.number(), z.string()]).nullish(), database: z.string().nullish() })
    .nullish(),
  password_known: z.boolean().nullish(),
  created_at: z.string().nullable().optional(),
  created_at_human: z.string().nullable().optional(),
});

export const databaseSchema = z.object({
  id: z.number(),
  name: z.string(),
  engine: z.string(),
  driver: z.string().nullable().optional(),
  charset: z.string().nullable().optional(),
  collation: z.string().nullable().optional(),
  application_id: z.number().nullable().optional(),
  application_name: z.string().nullable().optional(),
  size_bytes: z.number().nullable().optional(),
  size_human: z.string().nullable().optional(),
  users_count: z.number().nullable().optional(),
  created_at: z.string().nullable().optional(),
  created_at_human: z.string().nullable().optional(),
  users: z.array(databaseUserSchema).nullable().optional(),
});

export const databasesResponseSchema = z.object({
  databases: z.array(databaseSchema).default([]),
  meta: listMetaSchema,
});

export const databaseResponseSchema = z.object({ database: databaseSchema });

export const untrackedResponseSchema = z.object({
  untracked: z.array(z.string()).default([]),
});

const RESERVED_DATABASE_USERS = new Set(["root"]);

export function databaseUsernameProblem(value) {
  if (!/^[A-Za-z0-9_]+$/.test(value)) return "databaseUsername";
  if (value.length > 32) return "max32";
  if (RESERVED_DATABASE_USERS.has(value)) return "databaseUsernameReserved";
  return null;
}

export function hostProblem(value) {
  if (!value) return "required_host";
  const match = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})(?:\/(\d{1,2}))?$/.exec(value);
  if (!match) return "databaseHost";
  if (match.slice(1, 5).some((octet) => Number(octet) > 255)) return "databaseHost";
  if (match[5] !== undefined && Number(match[5]) > 32) return "databaseHost";
  return null;
}

export const createDatabaseSchema = (reserved = reservedNames()) =>
  z
  .object({
    name: z
      .string()
      .trim()
      .min(1, "required_name")
      .max(63, "tooLong")
      .regex(DATABASE_NAME, "databaseName")
      .refine((value) => !reserved.has(value.toLowerCase()), "databaseNameReserved"),
    engine: z.string().min(1, "required_engine"),
    application_id: z.string().optional(),
    charset: z.string().optional(),
    collation: z.string().optional(),
    create_user: z.boolean().default(true),
    username: z.string().trim().optional(),
    password: z.string().optional(),
    connection_preference: z.enum(CONNECTION_PREFERENCES).default("localhost"),
    host: z.string().trim().optional(),
  })
  .superRefine((values, ctx) => {
    if (!values.create_user) return;

    if (!values.username) {
      ctx.addIssue({
        code: "custom",
        path: ["username"],
        message: "required_username",
      });
    } else {
      const problem = databaseUsernameProblem(values.username);
      if (problem) ctx.addIssue({ code: "custom", path: ["username"], message: problem });
    }

    if (values.connection_preference === "remote") {
      const problem = hostProblem(values.host);
      if (problem) ctx.addIssue({ code: "custom", path: ["host"], message: problem });
    }
  });

// An empty password on save means keep the current one.
export const connectionSchema = z.object({
  engine: z.string(),
  driver: z.string().nullable().optional(),
  connection_type: z.string().nullable().optional(),
  host: z.string().nullable().optional(),
  port: z.number().nullable().optional(),
  socket: z.string().nullable().optional(),
  username: z.string().nullable().optional(),
  has_password: z.boolean().nullable().optional().default(false),
  has_root_password: z.boolean().nullish(),
  options: z.union([z.array(z.unknown()), z.record(z.string(), z.unknown())]).nullable().optional(),
});

export const rootPasswordSchema = z
  .object({
    password: z
      .string()
      .min(12, "min12")
      .max(128, "max128")
      // eslint-disable-next-line no-control-regex
      .refine((value) => !/[\x00-\x1f\x7f]/.test(value), "noControlChars"),
    password_confirmation: z.string(),
    current_password: z.string().max(512, "max512").optional(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    path: ["password_confirmation"],
    message: "passwordsMismatch",
  });

export const connectionsResponseSchema = z.object({
  connections: z.array(connectionSchema).default([]),
});

export const connectionFormSchema = z
  .object({
    connection_type: z.enum(["tcp", "socket"]).default("tcp"),
    host: z.string().trim().optional(),
    port: z.string().trim().optional(),
    socket: z.string().trim().optional(),
    username: z.string().trim().min(1, "required_username"),
    password: z.string().optional(),
  })
  .superRefine((values, ctx) => {
    if (values.connection_type === "tcp") {
      if (!values.host) {
        ctx.addIssue({ code: "custom", path: ["host"], message: "required_host" });
      }
      const port = Number(values.port);
      if (!values.port || !Number.isInteger(port) || port < 1 || port > 65535) {
        ctx.addIssue({ code: "custom", path: ["port"], message: "invalidPort" });
      }
      return;
    }
    if (!values.socket) {
      ctx.addIssue({ code: "custom", path: ["socket"], message: "required_socket" });
    }
  });

export const usersResponseSchema = z.object({
  users: z.array(databaseUserSchema).default([]),
});

export const databaseUserFormSchema = z
  .object({
    username: z
      .string()
      .trim()
      .min(1, "required_username")
      .superRefine((value, ctx) => {
        const problem = databaseUsernameProblem(value);
        if (problem) ctx.addIssue({ code: "custom", message: problem });
      }),
    password: z
      .string()
      .optional()
      .refine((value) => !value || value.length >= 8, "min8")
      .refine((value) => !value || value.length <= 255, "max255"),
    connection_preference: z.enum(CONNECTION_PREFERENCES).default("localhost"),
    host: z.string().trim().optional(),
  })
  .superRefine((values, ctx) => {
    if (values.connection_preference === "remote") {
      const problem = hostProblem(values.host);
      if (problem) ctx.addIssue({ code: "custom", path: ["host"], message: problem });
    }
  });

export const passwordFormSchema = z.object({
  password: z.string().min(8, "min8").max(255, "max255"),
});

export const exportSchema = z.object({
  id: z.number(),
  database_id: z.number().nullable().optional(),
  database: z.string().nullable().optional(),
  engine: z.string().nullable().optional(),
  purpose: z.string().nullable().optional(),
  file: z.string().nullable().optional(),
  status: z.string(),
  size_bytes: z.number().nullable().optional(),
  size_human: z.string().nullable().optional(),
  reason: z.string().nullable().optional(),
  message: z.string().nullable().optional(),
  reference: z.string().nullable().optional(),
  available: z.boolean().nullable().optional().default(false),
  download_url: z.string().nullable().optional(),
  requested_by: z
    .object({ id: z.number(), username: z.string() })
    .nullable()
    .optional(),
  created_at: z.string().nullable().optional(),
  created_at_human: z.string().nullable().optional(),
  finished_at: z.string().nullable().optional(),
  finished_at_human: z.string().nullable().optional(),
});

export const exportsResponseSchema = z.object({
  exports: z.array(exportSchema).default([]),
});

export const importSchema = z.object({
  id: z.number(),
  database_id: z.number().nullable().optional(),
  database: z.string().nullable().optional(),
  engine: z.string().nullable().optional(),
  file_name: z.string().nullable().optional(),
  size_bytes: z.number().nullable().optional(),
  size_human: z.string().nullable().optional(),
  received_bytes: z.number().nullable().optional(),
  status: z.string(),
  step: z.string().nullable().optional(),
  reason: z.string().nullable().optional(),
  message: z.string().nullable().optional(),
  line: z.number().nullable().optional(),
  reference: z.string().nullable().optional(),
  restored: z.boolean().nullable().optional(),
  safety_export_id: z.number().nullable().optional(),
  safety_copy: z.boolean().nullable().optional(),
  requested_by: z.object({ id: z.number(), username: z.string() }).nullable().optional(),
  created_at: z.string().nullable().optional(),
  created_at_human: z.string().nullable().optional(),
  finished_at: z.string().nullable().optional(),
  finished_at_human: z.string().nullable().optional(),
});

export const importsResponseSchema = z.object({
  imports: z.array(importSchema).default([]),
});

export const engineStatusSchema = z.object({
  connections: z.number().nullable().optional(),
  max_connections: z.number().nullable().optional(),
  threads_running: z.number().nullable().optional(),
  queries: z.number().nullable().optional(),
  slow_queries: z.number().nullable().optional(),
  uptime_seconds: z.number().nullable().optional(),
});

export const engineStatusResponseSchema = z.object({
  status: engineStatusSchema,
});

export const dbMetricSchema = z.object({
  sampled_at: z.string().nullable().optional(),
  qps: z.number().nullable().optional(),
  connections: z.number().nullable().optional(),
  threads_running: z.number().nullable().optional(),
});

export const dbMetricsResponseSchema = z.object({
  metrics: z.array(dbMetricSchema).default([]),
});

export const dbProcessSchema = z.object({
  id: z.union([z.number(), z.string()]),
  user: z.string().nullable().optional(),
  host: z.string().nullable().optional(),
  db: z.string().nullable().optional(),
  command: z.string().nullable().optional(),
  time: z.number().nullable().optional(),
  state: z.string().nullable().optional(),
  query: z.string().nullable().optional(),
  is_panel: z.boolean().nullish(),
});

export const dbProcessesResponseSchema = z.object({
  processes: z.array(dbProcessSchema).default([]),
});

export const dbTableSchema = z.object({
  name: z.string(),
  rows: z.number().nullable().optional(),
  size_bytes: z.number().nullable().optional(),
});

export const dbTablesResponseSchema = z.object({
  tables: z.array(dbTableSchema).default([]),
});
