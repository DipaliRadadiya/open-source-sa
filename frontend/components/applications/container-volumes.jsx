"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import Link from "next/link";
import { HardDrive, Plus, X } from "lucide-react";
import { volumeMountSchema } from "@/lib/schemas/docker";
import { updateContainerSettings } from "@/lib/api/docker";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Note } from "@/components/ui/note";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

/**
 * The volumes a container site mounts, and where.
 *
 * A list, not a field — which is the whole difference from the network. A site
 * joins one network; it mounts as many volumes as it has data worth keeping, and
 * each one carries a path INSIDE the container. That path is why "attach this
 * volume to a container" cannot be a single dropdown on the Docker page:
 * `shop-db` is only useful at `/var/lib/mysql`, and the same volume at
 * `/app/uploads` is a different thing entirely.
 *
 * Add and remove save immediately, rather than accumulating into a dirty form.
 * Same shape as `components/fail2ban/ignore-list-card.jsx`, and for the same
 * reason: a list of discrete facts has no half-finished state worth holding, and
 * each change here recreates the container — so batching them would hide how
 * many restarts a click is worth.
 */
export function ContainerVolumes({
  application,
  volumes = [],
  canManage = false,
}) {
  const t = useTranslations("applications.container.volumes");
  const router = useRouter();

  const mounts = application.volume_mounts ?? [];
  const [pending, setPending] = useState(false);
  const [volume, setVolume] = useState("");
  const [path, setPath] = useState("");
  const [error, setError] = useState(null);

  async function save(next, done) {
    setPending(true);
    setError(null);
    try {
      await updateContainerSettings(application.id, { volume_mounts: next });
      toast.success(done);
      router.refresh();
      return true;
    } catch (requestError) {
      // The server's own sentence. It is the only one that can say "that path is
      // where the site's own files are" — the rule needs to know what the
      // compose file mounts, which the browser does not.
      const message = apiMessage(requestError, t("failed"));
      setError(message);
      toast.error(message);
      return false;
    } finally {
      setPending(false);
    }
  }

  async function add(event) {
    event.preventDefault();

    const candidate = { volume, path: path.trim() };
    const parsed = volumeMountSchema.safeParse(candidate);

    if (!parsed.success) {
      setError(t("invalid"));

      return;
    }

    if (await save([...mounts, candidate], t("added", { volume }))) {
      setVolume("");
      setPath("");
    }
  }

  const unused = volumes.filter(
    (candidate) => !mounts.some((mount) => mount.volume === candidate.name),
  );

  return (
    <div className="space-y-3">
      <Label>{t("title")}</Label>

      <ul className="divide-y rounded-lg border">
        {mounts.length === 0 ? (
          <li className="px-3 py-6 text-center text-sm text-muted-foreground">
            {t("empty")}
          </li>
        ) : (
          mounts.map((mount) => (
            <li
              key={`${mount.volume}:${mount.path}`}
              className="flex items-center justify-between gap-2 px-3 py-2"
            >
              <span className="min-w-0 truncate font-mono text-sm">
                {mount.volume}
                <span className="text-muted-foreground"> → {mount.path}</span>
              </span>
              {canManage ? (
                <button
                  type="button"
                  disabled={pending}
                  aria-label={t("remove", { volume: mount.volume })}
                  className="shrink-0 rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50"
                  onClick={() =>
                    save(
                      mounts.filter(
                        (row) =>
                          !(
                            row.volume === mount.volume &&
                            row.path === mount.path
                          ),
                      ),
                      t("removed", { volume: mount.volume }),
                    )
                  }
                >
                  <X className="size-4" />
                </button>
              ) : null}
            </li>
          ))
        )}
      </ul>

      {canManage ? (
        volumes.length === 0 ? (
          <Note icon={HardDrive}>
            {t("noVolumes")}{" "}
            <Link href="/docker" className="font-medium text-primary underline">
              {t("noVolumesLink")}
            </Link>
          </Note>
        ) : (
          <form className="flex flex-wrap items-start gap-2" onSubmit={add}>
            <Select value={volume} onValueChange={setVolume} disabled={pending}>
              <SelectTrigger className="h-9 w-48">
                <SelectValue placeholder={t("choose")} />
              </SelectTrigger>
              <SelectContent>
                {/* Already-mounted volumes are still offered: one volume at two
                    paths is legal Docker, and the server rejects only a repeated
                    PATH. Filtering them out here would forbid something that
                    works. */}
                {unused.map((candidate) => (
                  <SelectItem key={candidate.name} value={candidate.name}>
                    {candidate.name}
                  </SelectItem>
                ))}
                {volumes
                  .filter((candidate) => !unused.includes(candidate))
                  .map((candidate) => (
                    <SelectItem key={candidate.name} value={candidate.name}>
                      {t("alreadyMounted", { name: candidate.name })}
                    </SelectItem>
                  ))}
              </SelectContent>
            </Select>
            <Input
              value={path}
              onChange={(event) => setPath(event.target.value)}
              placeholder={t("pathPlaceholder")}
              aria-label={t("pathLabel")}
              className="h-9 w-56 font-mono"
              disabled={pending}
            />
            <Button
              type="submit"
              size="sm"
              disabled={pending || !volume || !path.trim()}
            >
              <Plus className="size-4" />
              {t("add")}
            </Button>
          </form>
        )
      ) : null}

      {/* Persistent, not a toast: "that path is where the site's own files are"
          is a thing to read twice, and it arrives after the row has been typed. */}
      {error ? <p className="text-sm text-destructive">{error}</p> : null}

      <p className="text-xs text-muted-foreground">{t("hint")}</p>
    </div>
  );
}
