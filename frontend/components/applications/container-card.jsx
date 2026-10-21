"use client";

import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Network, KeyRound, RefreshCw, Gauge, FileCode, Package, HardDrive } from "lucide-react";
import Link from "@/components/ui/app-link";
import { containerSettingsFormSchema } from "@/lib/schemas/docker";
import { pullContainerImage, updateContainerSettings } from "@/lib/api/docker";
import { apiMessage } from "@/lib/api/error-message";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { InfoRow, Row, Section, SectionActions } from "@/components/settings/setting-row";
import { Input } from "@/components/ui/input";
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

/**
 * "No network — Docker's default bridge", as a value the form can hold.
 *
 * Radix reserves `""` for "nothing selected", so an empty `SelectItem` value
 * throws. One constant, used by the defaults, the item and the submit mapping,
 * because the bug here is the three of them disagreeing.
 */
const DEFAULT_NETWORK = "__default__";

/**
 * "No registry — pull anonymously", as a value the form can hold.
 *
 * The same Radix constraint as `DEFAULT_NETWORK`: `""` is reserved for "nothing
 * selected", so the default answer needs a value of its own — and it has to be the
 * same one in the defaults, the item and the submit mapping, which is the bug those
 * three have already had once on this form.
 */
const NO_REGISTRY = "__none__";

/**
 * What a container site runs as — and the only place the panel's own Docker
 * networks become usable.
 *
 * The network is the field that needed a home. `docker network create` takes no
 * container, so joining one is not something the create dialog on the Docker
 * page can do; and Compose cannot discover a network the panel made, so the
 * generated compose file has to name it with `external: true` or the site
 * quietly joins a differently-prefixed network of its own. Before this card
 * existed, the Docker page could create networks that nothing the panel builds
 * was able to use.
 *
 * `image` is deliberately not editable here. Changing it pulls, and a bad
 * reference fails after the old container is already gone — that belongs with
 * the deploy path, not with a form that saves settings. Shown read-only so the
 * card still answers "what is this running".
 *
 * A sentence about downtime, because saving this recreates the container: the
 * site is briefly down, and that is not something to discover from a graph.
 */
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
  const [portEditing, setPortEditing] = useState(false);
  const inspected = useImageInspection(application.image, application.registry_id);
  const detectedPort =
    inspected?.found && inspected.port_confidence !== "none"
      ? inspected.suggested_port ?? null
      : null;
  const { refreshAndWait } = useRefresh();
  const [savingImage, setSavingImage] = useState(false);
  const [savingSettings, setSavingSettings] = useState(false);
  const [pulling, setPulling] = useState(false);

  // The registry saves with the image card, the rest with the network card: each card
  // saves only its own fields (every field is optional on the PUT).
  const imageDefaults = {
    registry_id: application.registry_id
      ? String(application.registry_id)
      : NO_REGISTRY,
  };
  const defaults = {
    container_port: application.container_port ?? 80,
    memory_limit: application.memory_limit ?? "",
    cpu_limit: application.cpu_limit ?? "",
    // The sentinel, not `""`: Radix refuses an empty `SelectItem` value, and the
    // defaults, the item and the submit mapping must hold the SAME value or
    // "Its own network" goes to the API as "__default__".
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

  // The application's own network, kept in the list even when it is gone: dropping it
  // would show "Its own network" for an application that will not start.
  const missing =
    defaults.docker_network !== DEFAULT_NETWORK &&
    !networks.some((network) => network.name === defaults.docker_network);

  // Only networks somebody created to share. Docker's own break the published port,
  // and another application's network is that application's private one.
  const joinable = networks.filter(
    (network) =>
      (!network.built_in && !network.application_id) ||
      network.name === defaults.docker_network,
  );

  // Reachable: deleting a credential detaches its applications instead of refusing.
  const missingRegistry =
    imageDefaults.registry_id !== NO_REGISTRY &&
    !registries.some(
      (registry) => String(registry.id) === imageDefaults.registry_id,
    );

  async function put(payload, setBusy, target, values) {
    setBusy(true);
    try {
      await updateContainerSettings(application.id, payload);
      // Before the toast: the Docker page and the dashboard facts are server-rendered.
      await refreshAndWait();
      toast.success(t("saved"));
      target.reset(values);
    } catch (error) {
      if (!handleValidationError(error, target)) {
        toast.error(apiMessage(error, t("failed")));
      }
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
        // Empty means "no limit" / "server default" and must reach the API as null,
        // or a limit once set could never be taken off again.
        memory_limit: values.memory_limit === "" ? null : values.memory_limit,
        cpu_limit: values.cpu_limit === "" ? null : values.cpu_limit,
        docker_network:
          values.docker_network === DEFAULT_NETWORK ? null : values.docker_network,
      },
      setSavingSettings,
      form,
      values,
    );

  async function pull() {
    setPulling(true);
    try {
      await pullContainerImage(application.id);
      await refreshAndWait();
      toast.success(t("pulled"));
    } catch (error) {
      toast.error(apiMessage(error, t("pullFailed")));
    } finally {
      setPulling(false);
    }
  }

  const linkClass = "font-medium text-primary underline-offset-4 hover:underline";
  const showCredentials = canManage && (application.container_secret_keys ?? []).length > 0;

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <div className="space-y-6">
        <Form {...imageForm}>
          <form noValidate onSubmit={imageForm.handleSubmit(saveImage)}>
            <Section
              icon={Package}
              title={t("imageSection")}
              description={t("imageSectionHint")}
              changedBy={canManage ? t("restartNote") : null}
              actions={
                <SectionActions
                  label={t("saveImage")}
                  isDirty={imageForm.formState.isDirty}
                  pending={savingImage}
                  onDiscard={() => imageForm.reset(imageDefaults)}
                  canManage={canManage}
                />
              }
            >
              <InfoRow label={t("image")} hint={t("imageHint")}>
                {/* Not editable: a new image pulls, and a bad reference fails after the
                    old container is gone. Pull is its own button, not part of Save. */}
                <div className="flex flex-wrap items-center gap-2">
                  <code className="min-w-0 flex-1 truncate rounded-lg border bg-muted/40 px-3 py-2 font-mono text-xs">
                    {application.image || (ownCompose ? t("ownCompose.title") : t("noImage"))}
                  </code>
                  {canManage ? (
                    <Button type="button" variant="outline" disabled={pulling} onClick={pull}>
                      <RefreshCw className={pulling ? "size-4 animate-spin" : "size-4"} />
                      {t("pull")}
                    </Button>
                  ) : null}
                </div>
              </InfoRow>

              <FormField
                control={imageForm.control}
                name="registry_id"
                render={({ field }) => (
                  <Row
                    label={t("registry")}
                    hint={
                      registries.length === 0 ? (
                        // An empty chooser with no explanation reads as a broken control.
                        <>
                          {t("noRegistries")}{" "}
                          <Link href="/integrations/registries" className={linkClass}>
                            {t("noRegistriesLink")}
                          </Link>
                        </>
                      ) : (
                        t("registryHint")
                      )
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

        {/* A compose file the user owns (a 1-Click Recipe, or one taken over on the
            Compose File page) is what runs: port, limits, network and volumes saved
            here would answer "saved" and change nothing. */}
        {ownCompose ? (
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
                  description={t("settingsSectionHint")}
                  changedBy={canManage ? t("restartNote") : null}
                  actions={
                    <SectionActions
                      label={t("saveAction")}
                      isDirty={form.formState.isDirty}
                      pending={savingSettings}
                      onDiscard={() => form.reset(defaults)}
                      canManage={canManage}
                    />
                  }
                >
                  <FormField
                    control={form.control}
                    name="docker_network"
                    render={({ field }) => (
                      <Row
                        label={t("network")}
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

                  <FormField
                    control={form.control}
                    name="container_port"
                    render={({ field }) => {
                      // Matching what the image declares: shown as a fact, editable on request.
                      const matches = detectedPort && Number(field.value) === detectedPort;
                      return (
                        <Row label={t("containerPort")} hint={t("containerPortHint")}>
                          {matches && !portEditing ? (
                            <p className="flex min-h-9 flex-wrap items-center gap-x-2 text-sm">
                              <span className="font-mono text-sm font-medium">{field.value}</span>
                              <span className="text-muted-foreground">{tImage("portDetected")}</span>
                              {canManage ? (
                                <Button
                                  type="button"
                                  variant="link"
                                  className="h-auto p-0 text-sm"
                                  onClick={() => setPortEditing(true)}
                                >
                                  {tImage("portChange")}
                                </Button>
                              ) : null}
                            </p>
                          ) : (
                            <FormControl>
                              <Input type="number" inputMode="numeric" disabled={!canManage} {...field} />
                            </FormControl>
                          )}
                          {detectedPort && !matches ? (
                            // Main's higher-contrast amber; plain `text-warning` is faint on white.
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

                  <FormField
                    control={form.control}
                    name="memory_limit"
                    render={({ field }) => (
                      <Row
                        label={t("memoryLimit")}
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
                        // The server's real core count; the rule only when it could not be asked.
                        hint={limits.cpus ? t("cpuLimitHint", { cores: limits.cpus }) : t("cpuLimitHintUnknown")}
                      >
                        <FormControl>
                          {/* Empty here means NO limit, the opposite of memory's empty. */}
                          <Input placeholder={t("cpuLimitPlaceholder")} inputMode="decimal" disabled={!canManage} {...field} />
                        </FormControl>
                      </Row>
                    )}
                  />

                  {/* How the two limits fail, which is the part nobody knows: one line, not a box. */}
                  <p className="flex items-start gap-2 text-xs leading-5 text-muted-foreground">
                    <Gauge className="mt-0.5 size-3.5 shrink-0" />
                    {t("limitsBody")}
                  </p>

                  {missing ? (
                    <Note icon={Network} title={t("missingTitle")}>
                      {t("missingBody", { name: defaults.docker_network })}
                    </Note>
                  ) : null}

                  {/* The name to type: every generated file's service is `app`, so on a
                      shared network only the slug alias is unique. */}
                  {defaults.docker_network !== DEFAULT_NETWORK && !missing ? (
                    <Note icon={Network} title={t("reachableTitle")}>
                      {t("reachableBody", { alias: application.slug })}
                    </Note>
                  ) : null}
                </Section>
              </form>
            </Form>

            {/* Saves on add and remove: each change recreates the container, so it
                is not batched behind a Save. */}
            <Section icon={HardDrive} title={t("volumes.title")} description={t("volumes.hint")}>
              <ContainerVolumes application={application} volumes={volumes} canManage={canManage} />
            </Section>
          </>
        )}

        {/* Only for an application the panel generated credentials for, and only for
            someone who can manage it: the endpoint is gated on `manage`. */}
        {showCredentials ? (
          <Section icon={KeyRound} title={t("credentials.title")} description={t("credentials.hint")}>
            <ContainerCredentials application={application} />
          </Section>
        ) : null}
      </div>
    </DisabledReasonProvider>
  );
}
