import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { HardDrive, Loader2, Network } from "lucide-react";
import { listDockerNetworks, updateContainerSettings } from "@/lib/api/docker";
import { moveOutcome, offersKeepChoice, ownNetworkName } from "@/lib/docker/previous-network";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { FormModal } from "@/components/ui/form-modal";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import { Caution } from "@/components/ui/caution";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

export function AttachSiteDialog({
  open,
  onOpenChange,
  kind,
  name,
  sites = [],
  networks = [],
}) {
  const t = useTranslations("docker.attach");
  const tc = useTranslations("common");
  const tMove = useTranslations("docker.previousNetwork");
  const { refreshAndWait } = useRefresh();

  const [site, setSite] = useState("");
  const [path, setPath] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);
  const [removePrevious, setRemovePrevious] = useState(true);

  const isVolume = kind === "volume";
  const chosen = sites.find((candidate) => String(candidate.id) === site);
  const keepChoice =
    !isVolume &&
    Boolean(chosen) &&
    offersKeepChoice({ applicationId: chosen.id, current: chosen.docker_network ?? null, next: name, networks });

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
        : { docker_network: name, ...(keepChoice ? { remove_previous_network: removePrevious } : {}) };

      await updateContainerSettings(chosen.id, payload);
      await refreshAndWait();
      toast.success(keepChoice ? await movedMessage(chosen.id) : t("attached", { name, site: chosen.name }));
      onOpenChange(false);
      setSite("");
      setPath("");
      setRemovePrevious(true);
    } catch (requestError) {
      setError(apiMessage(requestError, t("failed")));
    } finally {
      setPending(false);
    }
  }

  async function movedMessage(applicationId) {
    try {
      const networksAfter = await listDockerNetworks();
      const outcome = moveOutcome({ applicationId, removeRequested: removePrevious, networksAfter });
      return tMove(outcome, { network: name, name: ownNetworkName(applicationId) });
    } catch {
      return t("attached", { name, site: chosen.name });
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
            <SelectTrigger className="w-full">
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

        {keepChoice ? (
          <div className="flex items-start gap-3 rounded-lg border p-3" data-testid="remove-previous-network">
            <Checkbox
              id="attach-remove-previous-network"
              checked={removePrevious}
              onCheckedChange={(value) => setRemovePrevious(value === true)}
              disabled={pending}
              className="mt-0.5"
            />
            <div className="min-w-0 space-y-1">
              <Label htmlFor="attach-remove-previous-network" className="leading-snug">
                {tMove("label", { name: ownNetworkName(chosen.id) })}
              </Label>
              <p className="text-xs leading-relaxed text-muted-foreground">{tMove("hint")}</p>
            </div>
          </div>
        ) : null}

        <Caution>
          {t("restartWarning", { site: chosen?.name ?? t("theSite") })}
        </Caution>

        {error ? <p className="text-sm text-destructive">{error}</p> : null}
      </div>
    </FormModal>
  );
}
