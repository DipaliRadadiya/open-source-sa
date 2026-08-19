import { z } from "zod";
import { listMetaSchema } from "./list.js";

/**
 * Databases, their users, and the engines they run on.
 *
 * Three engines behind one shape: `mysql` and `mariadb` share the `sql` driver,
 * `mongodb` has its own. A database user belongs to exactly one database, so
 * users are always nested rather than a resource of their own.
 */

/** Names the server owns. Creating one is refused, so say so before the 422. */
export const RESERVED_NAMES = [
  "mysql",
  "information_schema",
  "performance_schema",
  "sys",
  "admin",
  "local",
  "config",
];

export const DATABASE_NAME = /^[A-Za-z0-9_]{1,63}$/;

export const CONNECTION_PREFERENCES = ["localhost", "remote", "anywhere"];

/**
 * `charsets` maps a charset to the collations it allows — a collation from the
 * wrong charset is a 422, so the second select is driven by the first.
 *
 * Mongo has no charsets and sends `[]` rather than `{}`; an array would fail a
 * record schema, so it is accepted and normalized away.
 */
const charsetsSchema = z
  .union([z.record(z.string(), z.array(z.string())), z.array(z.unknown())])
  .nullable()
  .optional()
  .transform((value) => (value && !Array.isArray(value) ? value : {}));

export const engineSchema = z.object({
  engine: z.string(),
  driver: z.string().nullable().optional(),
  // Reachable with the configured connection — NOT the same as installed.
  running: z.boolean().nullable().optional().default(false),
  // Present on the server, whether or not it is up. The field that separates
  // "install it" from "we cannot connect to it".
  installed: z.boolean().nullable().optional().default(false),
  // Null when nothing is on the server. With `running: false` and a version
  // present, the engine is there but we could not talk to it.
  version: z.string().nullable().optional(),
  charsets: charsetsSchema,
  // False for mongodb, which needs its own apt repository — so it gets no
  // install button rather than one that always fails.
  installable: z.boolean().nullable().optional().default(false),
  // `installing` | `failed` | null. Never "installed": a finished install
  // deletes its progress row so detection stays the single answer.
  install_status: z.string().nullable().optional(),
  install_reason: z.string().nullable().optional(),
  // The server's own sentence, in the caller's language. Ours would be a guess
  // about a failure we did not witness.
  install_message: z.string().nullable().optional(),
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
  // Ready to paste into an app's config — the thing people actually came for.
  connection_string: z.string().nullable().optional(),
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
  // Zero means nothing can connect to it — worth surfacing on the row.
  users_count: z.number().nullable().optional().default(0),
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

/**
 * Create a database, and optionally its first user in the same step.
 *
 * A database with no user cannot be connected to, so the user is opt-out rather
 * than a second errand. Messages are key tokens the form translates.
 */
export const createDatabaseSchema = z
  .object({
    name: z
      .string()
      .trim()
      .min(1, "required_name")
      .max(63, "tooLong")
      .regex(DATABASE_NAME, "databaseName")
      .refine(
        (value) => !RESERVED_NAMES.includes(value.toLowerCase()),
        "databaseNameReserved",
      ),
    engine: z.string().min(1, "required_engine"),
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
    } else if (!/^[A-Za-z0-9_]+$/.test(values.username)) {
      // Deliberately no max length: MySQL allows 32 and MariaDB 80, and the API
      // is the authority. Guessing stricter would refuse names it accepts.
      ctx.addIssue({
        code: "custom",
        path: ["username"],
        message: "databaseUsername",
      });
    }

    // The API requires a host for `remote` — `anywhere` is the wildcard.
    if (values.connection_preference === "remote" && !values.host) {
      ctx.addIssue({ code: "custom", path: ["host"], message: "required_host" });
    }
  });

/**
 * The admin connection the panel itself uses, per engine.
 *
 * Stored in the database rather than `.env`, and the password is never returned
 * — `has_password` says whether one exists, and an empty field on save means
 * "leave it alone" rather than "clear it".
 */
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
      // No max length on purpose: MySQL allows 32 and MariaDB 80, and the API
      // is the authority. Guessing stricter would refuse names it accepts.
      .regex(/^[A-Za-z0-9_]+$/, "databaseUsername"),
    password: z.string().optional(),
    connection_preference: z.enum(CONNECTION_PREFERENCES).default("localhost"),
    host: z.string().trim().optional(),
  })
  .superRefine((values, ctx) => {
    // `anywhere` is the wildcard; only `remote` names an address.
    if (values.connection_preference === "remote" && !values.host) {
      ctx.addIssue({ code: "custom", path: ["host"], message: "required_host" });
    }
  });

export const passwordFormSchema = z.object({
  password: z.string().min(8, "min8"),
});

/**
 * A dump. Queued work, so a row exists from the moment the button is pressed —
 * `file` and `download_url` stay null until it finishes.
 */
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
  // Stable code + the same thing worded in the viewer's language, plus the id
  // support will ask for.
  reason: z.string().nullable().optional(),
  message: z.string().nullable().optional(),
  reference: z.string().nullable().optional(),
  // False once the file has been removed from disk by hand — the row remains
  // but there is nothing to download.
  available: z.boolean().nullable().optional().default(false),
  download_url: z.string().nullable().optional(),
  requested_by: z.string().nullable().optional(),
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
  // Seconds in the current state — the number that says "this is stuck".
  time: z.number().nullable().optional(),
  state: z.string().nullable().optional(),
  query: z.string().nullable().optional(),
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
