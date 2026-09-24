"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Network, HardDrive, Plus, Trash2, Lock } from "lucide-react";
import {
  createDockerNetwork,
  createDockerVolume,
  deleteDockerNetwork,
  deleteDockerVolume,
} from "@/lib/api/docker";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";

/**
 * Docker's networks and volumes.
 *
 * One screen for both, because they are the same job — the shared objects an
 * application's containers sit on and write to — and splitting them would put
 * two half-empty tables on two pages.
 *
 * Every destructive control is either absent or confirmed. The API refuses the
 * same cases independently: a hidden button whose endpoint still works is a
 * worse state than a visible one, so the UI narrows what is offered and the
 * server decides what is allowed.
 */
export function DockerResourcesPanel({ initialNetworks, initialVolumes, canManage }) {
  const t = useTranslations("docker");
  const router = useRouter();

  const [pending, setPending] = useState(null);
  const [confirm, setConfirm] = useState(null);
  const [newNetwork, setNewNetwork] = useState("");
  const [newVolume, setNewVolume] = useState("");

  async function run(key, action, successMessage) {
    setPending(key);
    try {
      await action();
      toast.success(successMessage);
      router.refresh();
      return true;
    } catch (error) {
      // The server's own sentence: it names the containers on a busy network
      // and the count on a volume in use, which a generic message cannot.
      toast.error(apiMessage(error, t("failed")));
      return false;
    } finally {
      setPending(null);
    }
  }

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader className="flex-row items-center justify-between gap-4 space-y-0">
          <CardTitle className="flex items-center gap-2">
            <Network className="size-4" />
            {t("networks.title")}
          </CardTitle>
          {canManage ? (
            <form
              className="flex items-center gap-2"
              onSubmit={async (event) => {
                event.preventDefault();
                const name = newNetwork.trim();
                if (!name) return;
                const ok = await run("create-network", () => createDockerNetwork(name), t("networks.created", { name }));
                if (ok) setNewNetwork("");
              }}
            >
              <Input
                value={newNetwork}
                onChange={(event) => setNewNetwork(event.target.value)}
                placeholder={t("networks.placeholder")}
                className="h-9 w-48"
                aria-label={t("networks.placeholder")}
              />
              <Button type="submit" size="sm" disabled={pending === "create-network" || !newNetwork.trim()}>
                <Plus className="size-4" />
                {t("create")}
              </Button>
            </form>
          ) : null}
        </CardHeader>
        <CardContent>
          <p className="mb-4 text-sm text-muted-foreground">{t("networks.hint")}</p>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{t("columns.name")}</TableHead>
                <TableHead>{t("columns.driver")}</TableHead>
                <TableHead>{t("columns.attached")}</TableHead>
                <TableHead className="w-24" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {initialNetworks.map((network) => (
                <TableRow key={network.id || network.name}>
                  <TableCell className="font-mono text-xs">
                    {network.name}
                    {network.application_id ? (
                      <Badge variant="secondary" className="ml-2">
                        {t("ownedByApp", { id: network.application_id })}
                      </Badge>
                    ) : null}
                  </TableCell>
                  <TableCell className="text-sm">{network.driver}</TableCell>
                  <TableCell className="text-sm">
                    {network.containers.length === 0 ? (
                      <span className="text-muted-foreground">{t("none")}</span>
                    ) : (
                      <ul className="space-y-1">
                        {network.containers.map((container) => (
                          <li key={container.name} className="flex flex-wrap items-center gap-2">
                            <span className="font-mono text-xs">{container.name}</span>
                            {/*
                              Published and exposed are different things and
                              look identical unless someone says so: a bare
                              `3306/tcp` is reachable only from this network,
                              while `127.0.0.1:2368->2368/tcp` is reachable
                              from the host. Ghost and its MySQL sit on the
                              same network and differ exactly here.
                            */}
                            {container.ports.length === 0 ? (
                              <span className="text-xs text-muted-foreground">{t("networks.noPorts")}</span>
                            ) : (
                              container.ports.map((port) => (
                                <Badge
                                  key={port}
                                  variant={port.includes("->") ? "secondary" : "outline"}
                                  className="font-mono text-xs font-normal"
                                >
                                  {port}
                                </Badge>
                              ))
                            )}
                            {container.published ? null : (
                              <span className="text-xs text-muted-foreground">
                                {t("networks.internalOnly")}
                              </span>
                            )}
                          </li>
                        ))}
                      </ul>
                    )}
                  </TableCell>
                  <TableCell>
                    {/*
                      Docker's own networks get no button at all, and a label
                      instead of a disabled control: disabled says "not now",
                      and the truth is "never" — Docker recreates these on
                      restart, so a delete could only fail or do damage.
                    */}
                    {network.built_in ? (
                      <span className="flex items-center gap-1 text-xs text-muted-foreground">
                        <Lock className="size-3" />
                        {t("networks.builtIn")}
                      </span>
                    ) : canManage ? (
                      <Button
                        variant="ghost"
                        size="sm"
                        disabled={pending !== null}
                        onClick={() =>
                          setConfirm({
                            kind: "network",
                            name: network.name,
                            busy: network.containers.length > 0,
                            containers: network.containers.map((c) => c.name),
                          })
                        }
                      >
                        <Trash2 className="size-4" />
                      </Button>
                    ) : null}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex-row items-center justify-between gap-4 space-y-0">
          <CardTitle className="flex items-center gap-2">
            <HardDrive className="size-4" />
            {t("volumes.title")}
          </CardTitle>
          {canManage ? (
            <form
              className="flex items-center gap-2"
              onSubmit={async (event) => {
                event.preventDefault();
                const name = newVolume.trim();
                if (!name) return;
                const ok = await run("create-volume", () => createDockerVolume(name), t("volumes.created", { name }));
                if (ok) setNewVolume("");
              }}
            >
              <Input
                value={newVolume}
                onChange={(event) => setNewVolume(event.target.value)}
                placeholder={t("volumes.placeholder")}
                className="h-9 w-48"
                aria-label={t("volumes.placeholder")}
              />
              <Button type="submit" size="sm" disabled={pending === "create-volume" || !newVolume.trim()}>
                <Plus className="size-4" />
                {t("create")}
              </Button>
            </form>
          ) : null}
        </CardHeader>
        <CardContent>
          <p className="mb-4 text-sm text-muted-foreground">{t("volumes.hint")}</p>
          {initialVolumes.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t("volumes.empty")}</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t("columns.name")}</TableHead>
                  {/* The column people actually want, and the one
                      `docker volume ls` reports as N/A. */}
                  <TableHead>{t("columns.size")}</TableHead>
                  <TableHead>{t("columns.usedBy")}</TableHead>
                  <TableHead className="w-24" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {initialVolumes.map((volume) => (
                  <TableRow key={volume.name}>
                    <TableCell className="font-mono text-xs">
                      {volume.name}
                      {volume.application_id ? (
                        <Badge variant="secondary" className="ml-2">
                          {t("ownedByApp", { id: volume.application_id })}
                        </Badge>
                      ) : null}
                    </TableCell>
                    <TableCell className="text-sm">{volume.size || "—"}</TableCell>
                    <TableCell className="text-sm">
                      {volume.in_use ? (
                        <Badge>{t("volumes.inUse", { count: volume.containers })}</Badge>
                      ) : (
                        <span className="text-muted-foreground">{t("volumes.unused")}</span>
                      )}
                    </TableCell>
                    <TableCell>
                      {canManage ? (
                        <Button
                          variant="ghost"
                          size="sm"
                          disabled={pending !== null}
                          onClick={() =>
                            setConfirm({ kind: "volume", name: volume.name, busy: volume.in_use })
                          }
                        >
                          <Trash2 className="size-4" />
                        </Button>
                      ) : null}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <ConfirmDialog
        open={confirm !== null}
        onOpenChange={(open) => !open && setConfirm(null)}
        icon={Trash2}
        tone="destructive"
        title={confirm ? t(`${confirm.kind}s.removeTitle`, { name: confirm.name }) : ""}
        /*
          A volume holds the container's data, so the confirmation says so
          rather than asking "are you sure?" — the question people can answer
          is "does this delete my database", and the dialog should be the
          thing that answers it.
        */
        description={
          confirm
            ? confirm.busy
              ? t(`${confirm.kind}s.removeBusy`)
              : t(`${confirm.kind}s.removeBody`)
            : ""
        }
        confirmLabel={t("remove")}
        confirmVariant="destructive"
        confirmDisabled={confirm?.busy === true}
        pending={pending === "remove"}
        onConfirm={async () => {
          if (!confirm) return;
          const ok = await run(
            "remove",
            () =>
              confirm.kind === "network"
                ? deleteDockerNetwork(confirm.name)
                : deleteDockerVolume(confirm.name),
            t(`${confirm.kind}s.removed`, { name: confirm.name }),
          );
          if (ok) setConfirm(null);
        }}
      />
    </div>
  );
}
