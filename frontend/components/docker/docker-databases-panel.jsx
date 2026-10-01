"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Database, Plus, Trash2, Eye, Loader2 } from "lucide-react";
import {
  createDockerDatabase,
  deleteDockerDatabase,
  getDockerDatabaseCredentials,
} from "@/lib/api/docker";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { CopyButton } from "@/components/ui/copy-button";
import { Caution } from "@/components/ui/caution";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

/**
 * Database engines, as containers, in one click each.
 *
 * **Why they are here and not in the one-click application grid.** Every
 * application in this panel is an HTTP site: `domain` is required, provisioning
 * always writes a vhost, and a container site's vhost is `proxy_pass
 * http://127.0.0.1:<port>`. A database speaks its own wire protocol — as a "site"
 * it would hold a domain nobody types, be issued a certificate no browser can use,
 * and answer 502 for ever. So they are server-level objects beside networks and
 * volumes, which is what they are: something sites connect to.
 *
 * That is an argument about where, not about how much work it takes. Picking an
 * engine here is one click: the name and the newest version are filled in, and the
 * only required answer is the one the panel cannot guess.
 *
 * **The two addresses are the whole reason this table has four columns.** From
 * another container on the same network the host is the database's NAME; from the
 * server itself it is 127.0.0.1 and the published port. Those being different is
 * the single most confusing thing about a containerised database, so both are shown
 * rather than explained.
 */
export function DockerDatabasesPanel({
  databases = [],
  engines = [],
  networks = [],
  canManage = false,
}) {
  const t = useTranslations("docker.databases");
  const router = useRouter();

  const [creating, setCreating] = useState(null);
  const [pending, setPending] = useState(null);
  const [confirm, setConfirm] = useState(null);
  const [removeData, setRemoveData] = useState(false);
  const [secrets, setSecrets] = useState({});

  async function run(key, call, message) {
    setPending(key);
    try {
      await call();
      toast.success(message);
      // The listing is server-rendered, so without this the new row (or the gone
      // one) only appears on the next navigation.
      router.refresh();
      return true;
    } catch (error) {
      toast.error(apiMessage(error, t("failed")));
      return false;
    } finally {
      setPending(null);
    }
  }

  async function reveal(database) {
    setPending(`reveal-${database.id}`);
    try {
      const { data } = await getDockerDatabaseCredentials(database.id);
      setSecrets((current) => ({
        ...current,
        [database.id]: data?.credentials ?? {},
      }));
    } catch (error) {
      toast.error(apiMessage(error, t("revealFailed")));
    } finally {
      setPending(null);
    }
  }

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader className="flex-row items-center justify-between gap-4 space-y-0">
          <CardTitle className="flex items-center gap-2">
            <Database className="size-4" />
            {t("title")}
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <p className="text-sm text-muted-foreground">{t("hint")}</p>

          {/* The engines, as one control each. A single "add database" form with
              an engine dropdown hides the answer to "what can this server run",
              which is the question somebody arrives with. */}
          {canManage && engines.length ? (
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
              {engines.map((engine) => (
                <button
                  key={engine.name}
                  type="button"
                  onClick={() =>
                    setCreating({
                      engine: engine.name,
                      label: engine.label,
                      // The newest the catalog offers. The list is ordered newest
                      // first by the config, and a version nobody chose is better
                      // than an empty select somebody has to answer before they
                      // can see what this does.
                      version: engine.versions[0] ?? "",
                      versions: engine.versions,
                      port: engine.port,
                      name: suggestName(engine.name, databases),
                      network: "",
                    })
                  }
                  className="flex items-center justify-between gap-2 rounded-lg border bg-muted/30 px-3 py-2.5 text-left transition-colors hover:bg-muted/60"
                >
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-medium">
                      {engine.label}
                    </span>
                    <span className="block truncate font-mono text-xs text-muted-foreground">
                      {t("newest", { version: engine.versions[0] ?? "—" })}
                    </span>
                  </span>
                  <Plus className="size-4 shrink-0 text-muted-foreground" />
                </button>
              ))}
            </div>
          ) : null}

          {databases.length === 0 ? (
            <p className="rounded-lg border border-dashed px-3 py-6 text-center text-sm text-muted-foreground">
              {t("empty")}
            </p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t("columns.name")}</TableHead>
                  <TableHead>{t("columns.engine")}</TableHead>
                  <TableHead>{t("columns.fromContainer")}</TableHead>
                  <TableHead>{t("columns.fromServer")}</TableHead>
                  <TableHead>{t("columns.state")}</TableHead>
                  <TableHead className="w-28" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {databases.map((database) => (
                  <TableRow key={database.id}>
                    <TableCell className="font-mono text-xs">
                      {database.name}
                    </TableCell>
                    <TableCell className="text-xs">
                      {database.engine_label ?? database.engine}{" "}
                      <span className="font-mono text-muted-foreground">
                        {database.version}
                      </span>
                    </TableCell>
                    {/* The alias, and only when it can actually resolve: without a
                        shared network a container's name reaches nothing, so
                        printing it would be an address that does not work. */}
                    <TableCell className="font-mono text-xs">
                      {database.network ? (
                        <span className="flex items-center gap-1.5">
                          {database.internal_host}:{database.internal_port}
                          <CopyButton
                            value={`${database.internal_host}:${database.internal_port}`}
                          />
                        </span>
                      ) : (
                        <span className="text-muted-foreground">
                          {t("noNetwork")}
                        </span>
                      )}
                    </TableCell>
                    <TableCell className="font-mono text-xs">
                      <span className="flex items-center gap-1.5">
                        127.0.0.1:{database.host_port}
                        <CopyButton value={`127.0.0.1:${database.host_port}`} />
                      </span>
                    </TableCell>
                    <TableCell>
                      <Badge
                        variant={database.running ? "default" : "secondary"}
                      >
                        {database.running ? t("running") : t("stopped")}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right">
                      {canManage ? (
                        <span className="flex items-center justify-end gap-1">
                          <Button
                            variant="ghost"
                            size="icon-sm"
                            onClick={() => reveal(database)}
                            disabled={pending === `reveal-${database.id}`}
                            aria-label={t("reveal")}
                            title={t("reveal")}
                          >
                            {pending === `reveal-${database.id}` ? (
                              <Loader2 className="size-3.5 animate-spin" />
                            ) : (
                              <Eye className="size-3.5" />
                            )}
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon-sm"
                            onClick={() => {
                              setRemoveData(false);
                              setConfirm(database);
                            }}
                            aria-label={t("remove", { name: database.name })}
                            title={t("remove", { name: database.name })}
                          >
                            <Trash2 className="size-3.5" />
                          </Button>
                        </span>
                      ) : null}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}

          {/* Revealed credentials, under the table rather than inside a row: a
              password in a table cell is a password that gets truncated, and this
              is the one value where a clipped copy is worse than none. */}
          {Object.entries(secrets).map(([id, values]) => {
            const database = databases.find((d) => String(d.id) === String(id));
            if (!database) return null;
            return (
              <div key={id} className="space-y-2 rounded-lg border p-3">
                <p className="text-sm font-medium">
                  {t("credentialsFor", { name: database.name })}
                </p>
                <ul className="divide-y rounded-lg border">
                  {connectionRows(database, values).map((row) => (
                    <li
                      key={row.label}
                      className="flex items-center justify-between gap-2 px-3 py-2"
                    >
                      <span className="font-mono text-xs text-muted-foreground">
                        {row.label}
                      </span>
                      <span className="flex min-w-0 items-center gap-1.5">
                        <code className="min-w-0 break-all font-mono text-xs">
                          {row.value}
                        </code>
                        <CopyButton value={row.value} />
                      </span>
                    </li>
                  ))}
                </ul>
              </div>
            );
          })}
        </CardContent>
      </Card>

      {/* Create. One required answer — the name — with everything else already
          chosen, which is what makes this a click rather than a form. */}
      <ConfirmDialog
        open={creating !== null}
        onOpenChange={(open) => !open && setCreating(null)}
        icon={Database}
        title={creating ? t("createTitle", { engine: creating.label }) : ""}
        description={t("createBody")}
        confirmLabel={t("create")}
        confirmDisabled={!creating?.name?.trim() || !creating?.version}
        pending={pending === "create"}
        onConfirm={async () => {
          if (!creating) return;
          const ok = await run(
            "create",
            () =>
              createDockerDatabase({
                name: creating.name.trim(),
                engine: creating.engine,
                version: creating.version,
                docker_network: creating.network || null,
              }),
            t("created", { name: creating.name.trim() }),
          );
          if (ok) setCreating(null);
        }}
      >
        <div className="space-y-3">
          <div className="space-y-1.5">
            <Label htmlFor="docker-db-name">{t("nameLabel")}</Label>
            <Input
              id="docker-db-name"
              value={creating?.name ?? ""}
              onChange={(event) =>
                setCreating((current) => ({
                  ...current,
                  name: event.target.value,
                }))
              }
            />
            <p className="text-xs text-muted-foreground">{t("nameHint")}</p>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="docker-db-version">{t("versionLabel")}</Label>
            <Select
              value={creating?.version ?? ""}
              onValueChange={(version) =>
                setCreating((current) => ({ ...current, version }))
              }
            >
              <SelectTrigger id="docker-db-version">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {(creating?.versions ?? []).map((version) => (
                  <SelectItem key={version} value={version}>
                    {version}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {/* Optional, and the copy says what skipping it costs. A database with
              no network is reachable from the server only — which is a real
              answer, and the wrong one if a container site needs it. */}
          <div className="space-y-1.5">
            <Label htmlFor="docker-db-network">{t("networkLabel")}</Label>
            <Select
              value={creating?.network || NO_NETWORK}
              onValueChange={(network) =>
                setCreating((current) => ({
                  ...current,
                  network: network === NO_NETWORK ? "" : network,
                }))
              }
            >
              <SelectTrigger id="docker-db-network">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={NO_NETWORK}>
                  {t("noNetworkOption")}
                </SelectItem>
                {networks.map((network) => (
                  <SelectItem key={network.name} value={network.name}>
                    {network.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">{t("networkHint")}</p>
          </div>

          <Caution size="md">
            <p>{t("createWarning")}</p>
          </Caution>
        </div>
      </ConfirmDialog>

      <ConfirmDialog
        open={confirm !== null}
        onOpenChange={(open) => !open && setConfirm(null)}
        icon={Trash2}
        tone="destructive"
        title={confirm ? t("removeTitle", { name: confirm.name }) : ""}
        description={t("removeBody")}
        confirmLabel={t("removeConfirm")}
        confirmVariant="destructive"
        pending={pending === "remove"}
        onConfirm={async () => {
          if (!confirm) return;
          const ok = await run(
            "remove",
            () => deleteDockerDatabase(confirm.id, { removeData }),
            t("removed", { name: confirm.name }),
          );
          if (ok) setConfirm(null);
        }}
      >
        {/* Opt-in, and off by default. The volume holds the data: removing the
            container is recoverable and removing the volume is not, so the two
            cannot be the same click. */}
        <div className="flex items-start gap-3 rounded-lg border bg-muted/40 p-3">
          <Checkbox
            id="docker-db-remove-data"
            checked={removeData}
            onCheckedChange={(value) => setRemoveData(value === true)}
            className="mt-0.5"
          />
          <div className="space-y-1">
            <Label
              htmlFor="docker-db-remove-data"
              className="text-sm font-medium"
            >
              {t("removeData")}
            </Label>
            <p className="text-xs leading-5 text-muted-foreground">
              {removeData ? t("removeDataOn") : t("removeDataOff")}
            </p>
          </div>
        </div>
      </ConfirmDialog>
    </div>
  );
}

/** Radix reserves `""` for "nothing selected", so "no network" needs a value. */
const NO_NETWORK = "__none__";

/**
 * A name that is free, so the dialog opens with one answer already filled.
 *
 * The engine's own name first — `postgres` — then `postgres-2` and upward. Checked
 * against what exists because the API refuses a duplicate, and offering a name that
 * will be rejected is worse than offering none.
 */
function suggestName(engine, databases) {
  const taken = new Set(databases.map((database) => database.name));
  if (!taken.has(engine)) return engine;
  for (let n = 2; n < 100; n += 1) {
    if (!taken.has(`${engine}-${n}`)) return `${engine}-${n}`;
  }
  return "";
}

/**
 * The connection details, in the order somebody fills a client dialog in.
 *
 * Built from `credential_keys` rather than from a fixed list: Redis and Valkey have
 * no user and no database — a password is the whole of their auth — so a fixed list
 * would render empty fields for concepts the engine does not have.
 */
function connectionRows(database, values) {
  const rows = [];

  if (database.network) {
    rows.push({ label: "host (container)", value: database.internal_host });
    rows.push({
      label: "port (container)",
      value: String(database.internal_port),
    });
  }
  rows.push({ label: "host (server)", value: "127.0.0.1" });
  rows.push({ label: "port (server)", value: String(database.host_port) });

  if (database.username)
    rows.push({ label: "username", value: database.username });
  if (database.database)
    rows.push({ label: "database", value: database.database });
  if (values?.password)
    rows.push({ label: "password", value: values.password });
  if (values?.root_password) {
    rows.push({ label: "root password", value: values.root_password });
  }

  return rows;
}
