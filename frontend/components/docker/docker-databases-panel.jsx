"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Database, Trash2, Eye, Loader2 } from "lucide-react";
import {
  deleteDockerDatabase,
  getDockerDatabaseCredentials,
} from "@/lib/api/docker";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { CopyButton } from "@/components/ui/copy-button";
import { Checkbox } from "@/components/ui/checkbox";
import Link from "next/link";
import { Label } from "@/components/ui/label";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

/**
 * The containerised database engines on this server, listed and managed.
 *
 * **Listing only — creating happens in the application create grid.** A database
 * is not an application (every application here has a required domain and a vhost,
 * and a database has neither) but "create a database" is something people go to the
 * create page to do, so that is the one place that starts one. Two creation
 * surfaces would be two sets of defaults to keep in step.
 *
 * This screen is the Databases page, which this stack used to hide: a Docker server
 * manages no HOST engine, so the tab was pointless and its endpoints answered 409.
 * It manages these, so the tab is back and this is what it shows.
 *
 * **The two addresses are the whole reason this table has four columns.** From
 * another container on the same network the host is the database's NAME; from the
 * server itself it is 127.0.0.1 and the published port. Those being different is
 * the single most confusing thing about a containerised database, so both are shown
 * rather than explained.
 */
export function DockerDatabasesPanel({
  databases = [],
  networks = [],
  canManage = false,
}) {
  const t = useTranslations("docker.databases");
  const router = useRouter();

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

          {databases.length === 0 ? (
            <p className="rounded-lg border border-dashed px-3 py-6 text-center text-sm text-muted-foreground">
              {t("empty")}{" "}
              {/* Named, with a link. "No databases yet" on a screen with no way to
                  make one is a dead end — creating happens in the application grid,
                  which is not a place somebody would guess from here. */}
              <Link
                href="/applications/create"
                className="font-medium text-primary underline"
              >
                {t("emptyLink")}
              </Link>
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
