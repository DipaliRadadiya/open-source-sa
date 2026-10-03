import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { HardDrive, Loader2, Network } from "lucide-react";
import { updateContainerSettings } from "@/lib/api/docker";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { FormModal } from "@/components/ui/form-modal";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Caution } from "@/components/ui/caution";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

/**
 * Attach a site to a network or a volume, from the Docker page.
 *
 * The same write as the Container card on the site's own page — one endpoint, so
 * the two can never disagree about what attaching means. This page is the other
 * door into it, because somebody who has just created a network is already here
 * and should not have to go and find the site.
 *
 * It says the container will be recreated, and then recreates it. The
 * alternative — record it now, apply on the next deploy — leaves the panel
 * showing a network the container is not on, which is the exact trap the card
 * was built to avoid.
 *
 * A volume additionally needs a path inside the container: `shop-db` is only
 * useful at `/var/lib/mysql`. That is why this dialog has a second field for a
 * volume and not for a network, and why the volume CREATE dialog has neither —
 * `docker volume create` takes no container at all.
 */
export function AttachSiteDialog({
  open,
  onOpenChange,
  kind,
  name,
  sites = [],
}) {
  const t = useTranslations("docker.attach");
  const tc = useTranslations("common");
  const { refreshAndWait } = useRefresh();

  const [site, setSite] = useState("");
  const [path, setPath] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);

  const isVolume = kind === "volume";
  const chosen = sites.find((candidate) => String(candidate.id) === site);

  // Already attached: for a network that is the site's single network; for a
  // volume, this same volume at any path. Offered anyway rather than filtered
  // out, because a volume at a second path is legitimate — the label says so
  // instead of the option vanishing.
  const attached = (candidate) =>
    isVolume
      ? (candidate.volume_mounts ?? []).some((mount) => mount.volume === name)
      : candidate.docker_network === name;

  async function submit(event) {
    event.preventDefault();
    if (!chosen) return;

    setPending(true);
    setError(null);
    try {
      const payload = isVolume
        ? {
            volume_mounts: [
              ...(chosen.volume_mounts ?? []),
              { volume: name, path: path.trim() },
            ],
          }
        : { docker_network: name };

      await updateContainerSettings(chosen.id, payload);
      await refreshAndWait();
      toast.success(t("attached", { name, site: chosen.name }));
      onOpenChange(false);
      setSite("");
      setPath("");
    } catch (requestError) {
      // The server's sentence: it is the only thing that can say "that path is
      // where the site's own files are".
      setError(apiMessage(requestError, t("failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <FormModal
      open={open}
      onOpenChange={onOpenChange}
      asForm
      onSubmit={submit}
      icon={isVolume ? HardDrive : Network}
      title={t(isVolume ? "volumeTitle" : "networkTitle", { name })}
      description={t(isVolume ? "volumeBody" : "networkBody")}
      footer={
        <>
          <Button
            type="button"
            variant="outline"
            disabled={pending}
            onClick={() => onOpenChange(false)}
          >
            {t("cancel")}
          </Button>
          <Button
            type="submit"
            disabled={pending || !site || (isVolume && !path.trim())}
            disabledReason={!site ? tc("chooseAnOption") : isVolume && !path.trim() ? tc("enterAValue") : null}
          >
            {pending && <Loader2 className="size-4 animate-spin" />}
            {pending ? t("attaching") : t("attach")}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="space-y-2">
          <Label>{t("site")}</Label>
          <Select value={site} onValueChange={setSite} disabled={pending}>
            <SelectTrigger>
              <SelectValue placeholder={t("chooseSite")} />
            </SelectTrigger>
            <SelectContent>
              {sites.length === 0 ? (
                <SelectItem value="__none__" disabled>
                  {t("noSites")}
                </SelectItem>
              ) : (
                sites.map((candidate) => (
                  <SelectItem key={candidate.id} value={String(candidate.id)}>
                    {attached(candidate)
                      ? t("alreadyAttached", { site: candidate.name })
                      : candidate.name}
                  </SelectItem>
                ))
              )}
            </SelectContent>
          </Select>
        </div>

        {isVolume ? (
          <div className="space-y-2">
            <Label>{t("path")}</Label>
            <Input
              value={path}
              onChange={(event) => setPath(event.target.value)}
              placeholder="/var/lib/mysql"
              className="font-mono"
              disabled={pending}
            />
            <p className="text-xs text-muted-foreground">{t("pathHint")}</p>
          </div>
        ) : null}

        {/* Said before the click. Attaching rewrites the site's compose file and
            brings the container up again, which is a few seconds of downtime for
            a site the person doing this may not have been thinking about. */}
        <Caution>
          {t("restartWarning", { site: chosen?.name ?? t("theSite") })}
        </Caution>

        {error ? <p className="text-sm text-destructive">{error}</p> : null}
      </div>
    </FormModal>
  );
}
