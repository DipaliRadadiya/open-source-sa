"use client";

import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { containerRedeployFailure, containerRedeployInFlight } from "@/lib/applications/settled";
import { Network, KeyRound, RefreshCw, Gauge, FileCode, Package, HardDrive } from "lucide-react";
import Link from "@/components/ui/app-link";
import { containerSettingsFormSchema } from "@/lib/schemas/docker";
import { listDockerNetworks, pullContainerImage, updateContainerSettings } from "@/lib/api/docker";
import { moveOutcome, offersKeepChoice, ownNetworkName } from "@/lib/docker/previous-network";
import { apiMessage } from "@/lib/api/error-message";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { InfoRow, Row, Section, SectionActions } from "@/components/settings/setting-row";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Note } from "@/components/ui/note";
import { Button } from "@/components/ui/button";
import { ContainerVolumes } from "@/components/applications/container-volumes";
import { ContainerCredentials } from "@/components/applications/container-credentials";
import { useImageInspection } from "@/lib/docker/use-image-inspection";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Form, FormControl, FormField } from "@/components/ui/form";

// Radix throws on an empty SelectItem value, so the default network needs its own value.
const DEFAULT_NETWORK = "__default__";

// Radix reserves "" for no selection.
const NO_REGISTRY = "__none__";

export function ContainerCard({
  application,
  networks = [],
  volumes = [],
  registries = [],
  limits = {},
  canManage = false,
  ownCompose = false,
}) {
  const t = useTranslations("applications.container");
  const tImage = useTranslations("applications.dockerImage");
  const inspected = useImageInspection(application.image, application.registry_id);
  const detectedPort =
    inspected?.found && inspected.port_confidence !== "none"
      ? inspected.suggested_port ?? null
      : null;
  const { refreshAndWait } = useRefresh();
  const [savingImage, setSavingImage] = useState(false);
  const [savingSettings, setSavingSettings] = useState(false);
  const [pulling, setPulling] = useState(false);
  const tr = useTranslations("applications.redeploy");
  const lastRedeploy = application.container_redeploy ?? null;
  const redeploying = containerRedeployInFlight(application);
  const redeployFailure = containerRedeployFailure(lastRedeploy, t("pullFailed"));

  const isRecipe = application.origin?.kind === "recipe";
  const imageDefaults = {
    registry_id: application.registry_id
      ? String(application.registry_id)
      : NO_REGISTRY,
  };
  const defaults = {
    container_port: application.container_port ?? 80,
    memory_limit: application.memory_limit ?? "",
    cpu_limit: application.cpu_limit ?? "",
    // Must be the same sentinel everywhere or __default__ gets sent to the API.
    docker_network: application.docker_network ?? DEFAULT_NETWORK,
  };

  const imageForm = useForm({
    resolver: zodResolver(containerSettingsFormSchema.pick({ registry_id: true })),
    defaultValues: imageDefaults,
  });
  const form = useForm({
    resolver: zodResolver(containerSettingsFormSchema.omit({ registry_id: true })),
    defaultValues: defaults,
    mode: "onBlur",
  });

  const tMove = useTranslations("docker.previousNetwork");
  const [removePrevious, setRemovePrevious] = useState(true);
  const chosenNetwork = useWatch({ control: form.control, name: "docker_network" });
  const toNetwork = (value) => (value === DEFAULT_NETWORK ? null : value);
  const keepChoice = offersKeepChoice({
    applicationId: application.id,
    current: toNetwork(defaults.docker_network),
    next: toNetwork(chosenNetwork),
    networks,
  });
  const ownNetwork = ownNetworkName(application.id);

  const missing =
    defaults.docker_network !== DEFAULT_NETWORK &&
    !networks.some((network) => network.name === defaults.docker_network);

  const joinable = networks.filter(
    (network) =>
      (!network.built_in && !network.application_id) ||
      network.name === defaults.docker_network,
  );

  const missingRegistry =
    imageDefaults.registry_id !== NO_REGISTRY &&
    !registries.some(
      (registry) => String(registry.id) === imageDefaults.registry_id,
    );

  const PAIRED = "@3xl/section:row-span-2 @3xl/section:grid-rows-subgrid";

  const limitFields = (
    <>
      <FormField
        control={form.control}
        name="memory_limit"
        render={({ field }) => (
          <Row
            label={t("memoryLimit")}
            className={PAIRED}
            hint={
              limits.defaultMemoryLimit
                ? t("memoryLimitHint", { default: limits.defaultMemoryLimit })
                : t("memoryLimitHintUnknown")
            }
          >
            <FormControl>
              <Input placeholder={limits.defaultMemoryLimit ?? "512m"} disabled={!canManage} {...field} />
            </FormControl>
          </Row>
        )}
      />
      <FormField
        control={form.control}
        name="cpu_limit"
        render={({ field }) => (
          <Row
            label={t("cpuLimit")}
            className={PAIRED}
            hint={limits.cpus ? t("cpuLimitHint", { cores: limits.cpus }) : t("cpuLimitHintUnknown")}
          >
            <FormControl>
              <Input placeholder={t("cpuLimitPlaceholder")} inputMode="decimal" disabled={!canManage} {...field} />
            </FormControl>
          </Row>
        )}
      />
    </>
  );

  async function put(payload, setBusy, target, values, savedMessage = async () => t("saved")) {
    setBusy(true);
    try {
      await updateContainerSettings(application.id, payload);
      await refreshAndWait();
      toast.success(await savedMessage());
      target.reset(values);
    } catch (error) {
      handleValidationError(error, target, { fallback: t("failed") });
    } finally {
      setBusy(false);
    }
  }

  const saveImage = (values) =>
    put(
      { registry_id: values.registry_id === NO_REGISTRY ? null : Number(values.registry_id) },
      setSavingImage,
      imageForm,
      values,
    );

  const saveSettings = (values) =>
    put(
      {
        container_port: values.container_port,
        // Empty must be sent as null, or a limit could never be removed.
        memory_limit: values.memory_limit === "" ? null : values.memory_limit,
        cpu_limit: values.cpu_limit === "" ? null : values.cpu_limit,
        docker_network:
          values.docker_network === DEFAULT_NETWORK ? null : values.docker_network,
        ...(keepChoice ? { remove_previous_network: removePrevious } : {}),
      },
      setSavingSettings,
      form,
      values,
      keepChoice ? () => movedMessage(values.docker_network, removePrevious) : undefined,
    );

  const saveLimits = (values) =>
    put(
      {
        memory_limit: values.memory_limit === "" ? null : values.memory_limit,
        cpu_limit: values.cpu_limit === "" ? null : values.cpu_limit,
      },
      setSavingSettings,
      form,
      values,
    );

  async function movedMessage(network, removeRequested) {
    let networksAfter = networks;
    try {
      networksAfter = await listDockerNetworks();
    } catch {
      return t("saved");
    }
    const outcome = moveOutcome({ applicationId: application.id, removeRequested, networksAfter });
    return tMove(outcome, { network, name: ownNetwork });
  }

  async function pull() {
    setPulling(true);
    try {
      const res = await pullContainerImage(application.id);
      await refreshAndWait();
      toast.success(res.status === 202 ? tr("started") : t("pulled"));
    } catch (error) {
      toast.error(apiMessage(error, t("pullFailed")));
    } finally {
      setPulling(false);
    }
  }

  const linkClass = "font-medium text-primary underline-offset-4 hover:underline";
  const showCredentials = canManage && (application.container_secret_keys ?? []).length > 0;

  return (
    <DisabledReasonProvider denied={!canManage}>
      <div className="space-y-6">
        <Form {...imageForm}>
          <form noValidate onSubmit={imageForm.handleSubmit(saveImage)}>
            <Section
              icon={Package}
              title={t("imageSection")}
              changedBy={canManage ? t("restartNote") : null}
              actions={
                <SectionActions
                  label={t("saveImage")}
                  isDirty={imageForm.formState.isDirty}
                  pending={savingImage}
                  onDiscard={() => imageForm.reset(imageDefaults)}
                  canManage={canManage}
                  blockedReason={redeploying ? tr("busy") : null}
                />
              }
            >
              <InfoRow label={t("image")}>
                <div className="flex flex-wrap items-center gap-2">
                  <code className="min-w-0 flex-1 truncate rounded-lg border bg-muted/40 px-3 py-2 font-mono text-xs">
                    {application.image || (ownCompose ? t("ownCompose.title") : t("noImage"))}
                  </code>
                  {canManage ? (
                    <Button type="button" variant="outline" disabled={pulling || redeploying} onClick={pull}>
                      <RefreshCw className={pulling || redeploying ? "size-4 animate-spin" : "size-4"} />
                      {pulling || redeploying ? t("pulling") : t("pull")}
                    </Button>
                  ) : null}
                </div>
                {pulling || redeploying ? (
                  <p role="status" className="mt-2 text-xs text-muted-foreground">
                    {tr("runningHint")}
                  </p>
                ) : lastRedeploy?.status === "failed" ? (
                  <p role="status" className="mt-2 text-xs text-destructive">
                    {redeployFailure ? tr("lastFailed", { reason: redeployFailure }) : tr("lastFailedPlain")}
                    {lastRedeploy.reference ? ` ${tr("reference", { reference: lastRedeploy.reference })}` : null}
                  </p>
                ) : null}
              </InfoRow>

              <FormField
                control={imageForm.control}
                name="registry_id"
                render={({ field }) => (
                  <Row
                    label={t("registry")}
                    hint={
                      registries.length === 0 ? (
                        <>
                          {t("noRegistries")}{" "}
                          <Link href="/integrations/registries" className={linkClass}>
                            {t("noRegistriesLink")}
                          </Link>
                        </>
                      ) : null
                    }
                  >
                    <Select value={field.value} onValueChange={field.onChange} disabled={!canManage}>
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder={t("noRegistry")} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={NO_REGISTRY}>{t("noRegistry")}</SelectItem>
                        {missingRegistry ? (
                          <SelectItem value={imageDefaults.registry_id}>
                            {t("missingRegistryOption")}
                          </SelectItem>
                        ) : null}
                        {registries.map((registry) => (
                          <SelectItem key={registry.id} value={String(registry.id)}>
                            {registry.name} — {registry.registry}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </Row>
                )}
              />
            </Section>
          </form>
        </Form>

        {ownCompose && isRecipe ? (
          <>
            <Form {...form}>
              <form noValidate onSubmit={form.handleSubmit(saveLimits)}>
                <Section
                  icon={Gauge}
                  title={t("recipeLimits.title")}
                  description={t("recipeLimits.hint")}
                  changedBy={canManage ? t("restartNote") : null}
                  actions={
                    <SectionActions
                      label={t("saveAction")}
                      isDirty={form.formState.isDirty}
                      pending={savingSettings}
                      onDiscard={() => form.reset(defaults)}
                      canManage={canManage}
                      blockedReason={redeploying ? tr("busy") : null}
                    />
                  }
                >
                  {limitFields}
                  <p className="flex items-start gap-2 text-xs leading-5 text-muted-foreground">
                    <Gauge className="mt-0.5 size-3.5 shrink-0" />
                    {t("limitsBody")}
                  </p>
                </Section>
              </form>
            </Form>
            <Note icon={FileCode} title={t("recipeLimits.composeTitle")}>
              {t("recipeLimits.composeBody")}{" "}
              <Link href={`/applications/${application.id}/compose`} className={linkClass}>
                {t("ownCompose.link")}
              </Link>
            </Note>
          </>
        ) : ownCompose ? (
          <Note icon={FileCode} title={t("ownCompose.title")}>
            {t("ownCompose.body")}{" "}
            <Link href={`/applications/${application.id}/compose`} className={linkClass}>
              {t("ownCompose.link")}
            </Link>
          </Note>
        ) : (
          <>
            <Form {...form}>
              <form noValidate onSubmit={form.handleSubmit(saveSettings)}>
                <Section
                  icon={Gauge}
                  title={t("settingsSection")}
                  changedBy={canManage ? t("restartNote") : null}
                  actions={
                    <SectionActions
                      label={t("saveAction")}
                      isDirty={form.formState.isDirty}
                      pending={savingSettings}
                      onDiscard={() => form.reset(defaults)}
                      canManage={canManage}
                      blockedReason={redeploying ? tr("busy") : null}
                    />
                  }
                >
                  <FormField
                    control={form.control}
                    name="docker_network"
                    render={({ field }) => (
                      <Row
                        label={t("network")}
                        className={PAIRED}
                        hint={
                          joinable.length === 0 ? (
                            <>
                              {t("noNetworks")}{" "}
                              <Link href="/docker" className={linkClass}>
                                {t("noNetworksLink")}
                              </Link>
                            </>
                          ) : (
                            t("networkHint")
                          )
                        }
                      >
                        <Select value={field.value} onValueChange={field.onChange} disabled={!canManage}>
                          <FormControl>
                            <SelectTrigger className="w-full">
                              <SelectValue placeholder={t("ownNetwork")} />
                            </SelectTrigger>
                          </FormControl>
                          <SelectContent>
                            <SelectItem value={DEFAULT_NETWORK}>{t("ownNetwork")}</SelectItem>
                            {missing ? (
                              <SelectItem value={defaults.docker_network}>
                                {t("missingOption", { name: defaults.docker_network })}
                              </SelectItem>
                            ) : null}
                            {joinable.map((network) => (
                              <SelectItem key={network.name} value={network.name}>
                                {network.name}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </Row>
                    )}
                  />

                  {keepChoice ? (
                    <div className="flex items-start gap-3 rounded-lg border p-3" data-testid="remove-previous-network">
                      <Checkbox
                        id="remove-previous-network"
                        checked={removePrevious}
                        onCheckedChange={(value) => setRemovePrevious(value === true)}
                        disabled={!canManage || savingSettings}
                        className="mt-0.5"
                      />
                      <div className="min-w-0 space-y-1">
                        <Label htmlFor="remove-previous-network" className="leading-snug">
                          {tMove("label", { name: ownNetwork })}
                        </Label>
                        <p className="text-xs leading-relaxed text-muted-foreground">{tMove("hint")}</p>
                      </div>
                    </div>
                  ) : null}

                  <FormField
                    control={form.control}
                    name="container_port"
                    render={({ field }) => {
                      const matches = detectedPort && Number(field.value) === detectedPort;
                      return (
                        <Row label={t("containerPort")} hint={t("containerPortHint")} className={PAIRED}>
                          <FormControl>
                            <Input type="number" inputMode="numeric" disabled={!canManage} {...field} />
                          </FormControl>
                          {detectedPort && !matches ? (
                            <p className="flex flex-wrap items-center gap-x-2 text-xs text-[color-mix(in_oklch,var(--warning)_75%,var(--foreground))] dark:text-warning">
                              <span>{tImage("portDiffers", { port: detectedPort })}</span>
                              {canManage ? (
                                <Button
                                  type="button"
                                  variant="link"
                                  className="h-auto p-0 text-xs"
                                  onClick={() =>
                                    form.setValue("container_port", detectedPort, {
                                      shouldDirty: true,
                                      shouldValidate: true,
                                    })
                                  }
                                >
                                  {tImage("portUseDetected", { port: detectedPort })}
                                </Button>
                              ) : null}
                            </p>
                          ) : null}
                        </Row>
                      );
                    }}
                  />

                  {limitFields}

                  <p className="flex items-start gap-2 text-xs leading-5 text-muted-foreground">
                    <Gauge className="mt-0.5 size-3.5 shrink-0" />
                    {t("limitsBody")}
                  </p>

                  {missing ? (
                    <Note icon={Network} title={t("missingTitle")}>
                      {t("missingBody", { name: defaults.docker_network })}
                    </Note>
                  ) : null}

                  {defaults.docker_network !== DEFAULT_NETWORK && !missing ? (
                    <Note icon={Network} title={t("reachableTitle")}>
                      {t("reachableBody", { alias: application.slug })}
                    </Note>
                  ) : null}
                </Section>
              </form>
            </Form>

            <Section icon={HardDrive} title={t("volumes.title")} description={t("volumes.hint")}>
              <ContainerVolumes application={application} volumes={volumes} canManage={canManage} />
            </Section>
          </>
        )}

        {showCredentials ? (
          <Section icon={KeyRound} title={t("credentials.title")} description={t("credentials.hint")}>
            <ContainerCredentials application={application} />
          </Section>
        ) : null}
      </div>
    </DisabledReasonProvider>
  );
}
