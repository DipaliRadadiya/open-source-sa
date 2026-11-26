import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import Link from "@/components/ui/app-link";
import { HardDrive, Plus, X } from "lucide-react";
import { volumeMountSchema } from "@/lib/schemas/docker";
import { updateContainerSettings } from "@/lib/api/docker";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { IconTooltip } from "@/components/ui/icon-tooltip";
import { Input } from "@/components/ui/input";
import { Note } from "@/components/ui/note";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

export function ContainerVolumes({
  application,
  volumes = [],
  canManage = false,
}) {
  const t = useTranslations("applications.container.volumes");
  const tc = useTranslations("common");
  const { refreshAndWait } = useRefresh();

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
      await refreshAndWait();
      toast.success(done);
      return true;
    } catch (requestError) {
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
                <IconTooltip label={t("remove", { volume: mount.volume })}>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon-sm"
                  disabled={pending}
                  aria-label={t("remove", { volume: mount.volume })}
                  className="text-muted-foreground hover:text-destructive"
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
                </Button>
                </IconTooltip>
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
          // Not a form element: a nested form is invalid HTML and broke hydration. Enter adds the mount.
          <div className="flex flex-wrap items-start gap-2">
            <Select value={volume} onValueChange={setVolume} disabled={pending}>
              <SelectTrigger className="h-9 w-48">
                <SelectValue placeholder={t("choose")} />
              </SelectTrigger>
              <SelectContent>
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
              onKeyDown={(event) => {
                if (event.key !== "Enter") return;
                event.preventDefault();
                if (volume && path.trim() && !pending) add(event);
              }}
            />
            <Button
              type="button"
              onClick={add}
              size="sm"
              disabled={pending || !volume || !path.trim()}
              disabledReason={!volume ? tc("chooseAnOption") : !path.trim() ? tc("enterAValue") : null}
            >
              <Plus className="size-4" />
              {t("add")}
            </Button>
          </div>
        )
      ) : null}

      {error ? <p className="text-sm text-destructive">{error}</p> : null}

    </div>
  );
}
