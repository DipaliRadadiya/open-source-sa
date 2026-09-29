"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Network, HardDrive, Link2, Plus, Trash2, Lock } from "lucide-react";
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
import { CopyButton } from "@/components/ui/copy-button";
import { AttachSiteDialog } from "@/components/docker/attach-site-dialog";
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
export function DockerResourcesPanel({
  initialNetworks,
  initialVolumes,
  sites = [],
  canManage,
  canManageSites = false,
}) {
  const t = useTranslations("docker");
  const router = useRouter();

  const [pending, setPending] = useState(null);
  const [confirm, setConfirm] = useState(null);
  // `{ kind, name }` of the network or volume being attached to a site.
  const [attaching, setAttaching] = useState(null);
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
                    {/*
                      Sites that CHOSE this network, which is not the same as
                      the badge above: that one is inferred from Compose's
                      `sv-app-<id>_default` naming and says nothing about a site
                      that joined a network somebody else made. It is also what
                      the delete refuses on — a stopped site is attached to no
                      container and still names the network — so the reason a
                      Remove is refused has to be visible before it is clicked.
                    */}
                    {network.sites.length > 0 ? (
                      <div className="mt-1 flex flex-wrap gap-1">
                        {network.sites.map((site) => (
                          <Badge key={site.id} variant="outline" className="font-sans font-normal">
                            {site.name}
                          </Badge>
                        ))}
                      </div>
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
                    ) : (
                      <div className="flex items-center justify-end gap-1">
                        {/* Attaching writes the SITE's config, so it is gated on
                            the site permission rather than the Docker one. */}
                        {canManageSites ? (
                          <Button
                            variant="ghost"
                            size="sm"
                            disabled={pending !== null}
                            aria-label={t("attach.action")}
                            onClick={() => setAttaching({ kind: "network", name: network.name })}
                          >
                            <Link2 className="size-4" />
                          </Button>
                        ) : null}
                        {canManage ? (
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
                            // Mirrors the endpoint's second refusal. Without it
                            // the button's only possible outcome is a 409, and
                            // a control whose only outcome is an error is not a
                            // control.
                            sites: network.sites.map((site) => site.name),
                          })
                        }
                      >
                        <Trash2 className="size-4" />
                      </Button>
                        ) : null}
                      </div>
                    )}
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
                  {/* Where it is on the HOST. Wanted for the unglamorous reasons:
                      rsyncing a volume elsewhere, checking what is actually on
                      disk, pointing a support answer at a directory. */}
                  <TableHead>{t("columns.path")}</TableHead>
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
                    <TableCell className="text-xs">
                      {volume.mountpoint ? (
                        <span className="flex items-center gap-1">
                          {/* Long and uniform — `/var/lib/docker/volumes/<name>/_data`
                              — so it truncates from the LEFT, keeping the part that
                              differs between rows readable. */}
                          <code className="max-w-[16rem] truncate font-mono" dir="rtl" title={volume.mountpoint}>
                            {volume.mountpoint}
                          </code>
                          <CopyButton value={volume.mountpoint} />
                        </span>
                      ) : (
                        <span className="text-muted-foreground">—</span>
                      )}
                    </TableCell>
                    <TableCell className="text-sm">{volume.size || "—"}</TableCell>
                    <TableCell className="text-sm">
                      {/*
                        Names, not a count. "1 container(s)" tells somebody a
                        number; what they need before they can act — stop it,
                        delete the volume — is which container to go and look at.
                        The networks column beside this one already names them.

                        The count is the fallback, not the default. The names
                        come from `container inspect` and the count from `system
                        df -v`, so an empty list beside a non-zero count means
                        the panel could not ask — which is a different fact from
                        "nothing is using it" and must not render as the same
                        thing. The delete stays refused either way.
                      */}
                      {!volume.in_use ? (
                        <span className="text-muted-foreground">{t("volumes.unused")}</span>
                      ) : volume.container_names.length > 0 ? (
                        <div className="flex flex-wrap gap-1">
                          {volume.container_names.map((container) => (
                            <Badge key={container} className="font-mono text-xs font-normal">
                              {container}
                            </Badge>
                          ))}
                        </div>
                      ) : (
                        <Badge variant="outline">
                          {t("volumes.inUseUnnamed", { count: volume.containers })}
                        </Badge>
                      )}

                      {/* Which SITE mounts it and WHERE, beneath the container
                          names. A container name says which process holds the
                          volume; `alpha → /var/lib/mysql` says what the volume IS.
                          Shown even with nothing running, because a stopped site
                          still owns its data and is still what the guard refuses
                          on. */}
                      {volume.sites.length > 0 ? (
                        <ul className="mt-1 space-y-0.5">
                          {volume.sites.map((site) => (
                            <li key={`${site.id}:${site.path}`} className="text-xs text-muted-foreground">
                              {site.name}
                              {site.path ? <span className="font-mono"> → {site.path}</span> : null}
                            </li>
                          ))}
                        </ul>
                      ) : null}
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center justify-end gap-1">
                        {canManageSites ? (
                          <Button
                            variant="ghost"
                            size="sm"
                            disabled={pending !== null}
                            aria-label={t("attach.action")}
                            onClick={() => setAttaching({ kind: "volume", name: volume.name })}
                          >
                            <Link2 className="size-4" />
                          </Button>
                        ) : null}
                        {canManage ? (
                          <Button
                            variant="ghost"
                            size="sm"
                            disabled={pending !== null}
                            onClick={() =>
                              setConfirm({
                                kind: "volume",
                                name: volume.name,
                                busy: volume.in_use,
                                // Mirrors the endpoint's refusal for a volume a
                                // stopped site still mounts, where `in_use` is
                                // false and the data is still in there.
                                sites: volume.sites.map((site) => site.name),
                              })
                            }
                          >
                            <Trash2 className="size-4" />
                          </Button>
                        ) : null}
                      </div>
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
              : confirm.sites?.length
                ? // Named, not counted — the site to go and change.
                  t(`${confirm.kind}s.removeUsedBySites`, { sites: confirm.sites.join(", ") })
                : t(`${confirm.kind}s.removeBody`)
            : ""
        }
        confirmLabel={t("remove")}
        confirmVariant="destructive"
        confirmDisabled={confirm?.busy === true || (confirm?.sites?.length ?? 0) > 0}
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

      <AttachSiteDialog
        open={attaching !== null}
        onOpenChange={(open) => !open && setAttaching(null)}
        kind={attaching?.kind}
        name={attaching?.name ?? ""}
        sites={sites}
      />
    </div>
  );
}
