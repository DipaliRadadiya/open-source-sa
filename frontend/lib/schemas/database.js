import { z } from "zod";
import { listMetaSchema } from "./list.js";

// `mysql` and `mariadb` share the `sql` driver; `mongodb` has its own. Users
// belong to one database, so they are always nested.

// Fallback when the API publishes no `system_schemas`: the union across engines,
// since the check can run before an engine is chosen.
export const RESERVED_NAMES = [
  // MySQL / MariaDB
  "mysql",
  "information_schema",
  "performance_schema",
  "sys",
  // MongoDB
  "admin",
  "local",
  "config",
  // PostgreSQL: `template0` cannot be connected to; `postgres` is the
  // maintenance database.
  "postgres",
  "template0",
  "template1",
];

// The union across every engine (the check can run before one is chosen).
export function reservedNames(engines = []) {
  const published = engines.flatMap((engine) =>
    Array.isArray(engine?.system_schemas) ? engine.system_schemas : [],
  );
  const names = published.length ? published : RESERVED_NAMES;
  return new Set(names.map((name) => String(name).toLowerCase()));
}

export const DATABASE_NAME = /^[A-Za-z0-9_]{1,63}$/;

export const CONNECTION_PREFERENCES = ["localhost", "remote", "anywhere"];

// Titles/messages are already localized by Laravel for the viewer.
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

// A collation from the wrong charset is a 422. Mongo sends `[]` rather than
// `{}`, which a record schema would reject, so it is normalized away.
const charsetsSchema = z
  .union([z.record(z.string(), z.array(z.string())), z.array(z.unknown())])
  .nullable()
  .optional()
  .transform((value) => (value && !Array.isArray(value) ? value : {}));

export const engineSchema = z.object({
  engine: z.string(),
  driver: z.string().nullable().optional(),
  // False for PostgreSQL (access lives in `pg_hba.conf`, not managed). Defaults
  // true for older APIs.
  supports_remote_users: z.boolean().default(true),
  // The engine's own databases, which the panel must never create, drop or
  // alter. Read by `reservedNames()`.
  system_schemas: z.array(z.string()).nullish(),
  // `{ code, reason }`: a stable code plus a translated sentence, as on a
  // blocked site-type card.
  unavailable: z
    .object({ code: z.string(), reason: z.string() })
    .nullable()
    .optional(),
  // Reachable with the configured connection — NOT the same as installed.
  running: z.boolean().nullable().optional().default(false),
  // Present on the server, whether or not it is up: separates "install it"
  // from "cannot connect to it".
  installed: z.boolean().nullable().optional().default(false),
  // Null when nothing is on the server. With `running: false` and a version
  // present, the engine is there but could not be reached.
  version: z.string().nullable().optional(),
  charsets: charsetsSchema,
  // Config-driven capability; never infer it from the engine name.
  installable: z.boolean().nullable().optional().default(false),
  // `installing` | `failed` | null. Never "installed": a finished install
  // deletes its progress row so detection stays the single answer.
  install_status: z.string().nullable().optional(),
  install_reason: z.string().nullable().optional(),
  // The server's own sentence, in the caller's language.
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
  // Ready to paste into an app's config; null for view-only roles (it holds the password).
  connection_string: z.string().nullable().optional(),
  // Where to connect, sent to every viewer.
  connection: z
    .object({ host: z.string().nullish(), port: z.union([z.number(), z.string()]).nullish(), database: z.string().nullish() })
    .nullish(),
  // False for users adopted from a migrated server (only a hash exists); the
  // API then withholds `connection_string`.
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
  size_bytes: z.number().nullable().optional(),
  size_human: z.string().nullable().optional(),
  // No `.default(0)`: only the list counts users, and a default on the detail
  // payload would claim "no users" when the API sent nothing.
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

// The API's rules for a database user (StoreDatabaseUserRequest): 32 is
// MySQL 8's limit for every engine, and the server's own accounts are refused.
const RESERVED_DATABASE_USERS = new Set(["root"]);

export function databaseUsernameProblem(value) {
  if (!/^[A-Za-z0-9_]+$/.test(value)) return "databaseUsername";
  if (value.length > 32) return "max32";
  if (RESERVED_DATABASE_USERS.has(value)) return "databaseUsernameReserved";
  return null;
}

// IPv4 or IPv4/CIDR, as the API accepts; it refuses hostnames and IPv6.
export function hostProblem(value) {
  if (!value) return "required_host";
  const match = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})(?:\/(\d{1,2}))?$/.exec(value);
  // Range-check octets and prefix: the shape alone lets 999.999.999.999 through.
  if (!match) return "databaseHost";
  if (match.slice(1, 5).some((octet) => Number(octet) > 255)) return "databaseHost";
  if (match[5] !== undefined && Number(match[5]) > 32) return "databaseHost";
  return null;
}

// A first user is opt-out: a database with no user cannot be connected to.
// Messages are key tokens the form translates.
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
    // "" means "not linked to a site"; coerced to null at submit because the API
    // rejects an empty string.
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

    // The API requires a host for `remote` — `anywhere` is the wildcard.
    if (values.connection_preference === "remote") {
      const problem = hostProblem(values.host);
      if (problem) ctx.addIssue({ code: "custom", path: ["host"], message: problem });
    }
  });

// The password is never returned (`has_password`); an empty field on save means
// "leave it alone", not "clear it".
export const connectionSchema = z.object({
  engine: z.string(),
  driver: z.string().nullable().optional(),
  connection_type: z.string().nullable().optional(),
  host: z.string().nullable().optional(),
  port: z.number().nullable().optional(),
  socket: z.string().nullable().optional(),
  username: z.string().nullable().optional(),
  has_password: z.boolean().nullable().optional().default(false),
  options: z.union([z.array(z.unknown()), z.record(z.string(), z.unknown())]).nullable().optional(),
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

/** Add a user, or edit one. Password optional: the API generates a strong one. */
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
    // Blank means "generate one" (add) or "keep it" (edit); otherwise 8–255.
    password: z
      .string()
      .optional()
      .refine((value) => !value || value.length >= 8, "min8")
      .refine((value) => !value || value.length <= 255, "max255"),
    connection_preference: z.enum(CONNECTION_PREFERENCES).default("localhost"),
    host: z.string().trim().optional(),
  })
  .superRefine((values, ctx) => {
    // `anywhere` is the wildcard; only `remote` names an address.
    if (values.connection_preference === "remote") {
      const problem = hostProblem(values.host);
      if (problem) ctx.addIssue({ code: "custom", path: ["host"], message: problem });
    }
  });

export const passwordFormSchema = z.object({
  password: z.string().min(8, "min8").max(255, "max255"),
});

// The row exists once queued; `file` and `download_url` stay null until done.
export const exportSchema = z.object({
  id: z.number(),
  database_id: z.number().nullable().optional(),
  // A copied name, so a dump outlives the database it came from.
  database: z.string().nullable().optional(),
  engine: z.string().nullable().optional(),
  file: z.string().nullable().optional(),
  // queued | running | completed | failed
  status: z.string(),
  size_bytes: z.number().nullable().optional(),
  size_human: z.string().nullable().optional(),
    // Stable code, the localized message, and the support reference.
  reason: z.string().nullable().optional(),
  message: z.string().nullable().optional(),
  reference: z.string().nullable().optional(),
    // False once the file has been removed from disk by hand.
  available: z.boolean().nullable().optional().default(false),
  download_url: z.string().nullable().optional(),
    // An object (`{id, username}`, eager-loaded), not a string: a wrong shape
    // fails the whole list.
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

/** Engine health. Mongo returns nulls for the SQL-only fields. */
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

/** 24h series, sampled every 5 minutes. `qps` is a delta, not a counter. */
export const dbMetricSchema = z.object({
  sampled_at: z.string().nullable().optional(),
  qps: z.number().nullable().optional(),
  connections: z.number().nullable().optional(),
  threads_running: z.number().nullable().optional(),
});

export const dbMetricsResponseSchema = z.object({
  metrics: z.array(dbMetricSchema).default([]),
});

/** One live connection. `query` is what it is doing right now. */
export const dbProcessSchema = z.object({
  id: z.union([z.number(), z.string()]),
  user: z.string().nullable().optional(),
  host: z.string().nullable().optional(),
  db: z.string().nullable().optional(),
  command: z.string().nullable().optional(),
  // Seconds in the current state.
  time: z.number().nullable().optional(),
  state: z.string().nullable().optional(),
  query: z.string().nullable().optional(),
  // The panel's own connection (the one reading this list): never offered Stop.
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
