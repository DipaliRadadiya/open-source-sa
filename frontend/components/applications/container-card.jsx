"use client";

import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Network, KeyRound, RefreshCw, Gauge } from "lucide-react";
import Link from "@/components/ui/app-link";
import { containerSettingsFormSchema } from "@/lib/schemas/docker";
import { pullContainerImage, updateContainerSettings } from "@/lib/api/docker";
import { apiMessage } from "@/lib/api/error-message";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { CardSaveFooter } from "@/components/ui/card-save-footer";
import { Input } from "@/components/ui/input";
import { Note } from "@/components/ui/note";
import { Button } from "@/components/ui/button";
import { ContainerVolumes } from "@/components/applications/container-volumes";
import { ContainerCredentials } from "@/components/applications/container-credentials";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";

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
  className,
}) {
  const t = useTranslations("applications.container");
  const { refreshAndWait } = useRefresh();
  const [saving, setSaving] = useState(false);
  const [pulling, setPulling] = useState(false);

  const defaults = {
    container_port: application.container_port ?? 80,
    memory_limit: application.memory_limit ?? "",
    cpu_limit: application.cpu_limit ?? "",
    // The sentinel, not `""`. Radix refuses an empty `SelectItem` value — it
    // reserves that for "nothing selected" — so "Docker's default bridge" needs
    // a value of its own, and it has to be the SAME value in the defaults, the
    // item and the submit mapping. Using `""` here and a sentinel in the item
    // was the first version: picking the default left the form holding a string
    // that mapped to nothing, so `docker_network: "__default__"` went to the
    // API and came back as a validation error on a field the user had just set
    // to "none".
    docker_network: application.docker_network ?? DEFAULT_NETWORK,
    registry_id: application.registry_id
      ? String(application.registry_id)
      : NO_REGISTRY,
  };

  const form = useForm({
    resolver: zodResolver(containerSettingsFormSchema),
    defaultValues: defaults,
    mode: "onBlur",
  });

  // The site's own network, kept in the list even when it is no longer on the
  // box. Dropping it would silently reset the chooser to "default bridge" and
  // show a value the site does not have — and this is exactly the case worth
  // seeing, because that site will not start.
  const missing =
    defaults.docker_network !== DEFAULT_NETWORK &&
    !networks.some((network) => network.name === defaults.docker_network);

  // Same idea for the registry, and it is a REACHABLE state here rather than a
  // defensive one: deleting a credential detaches its sites instead of refusing,
  // so a site can legitimately point at a row that is gone.
  const missingRegistry =
    defaults.registry_id !== NO_REGISTRY &&
    !registries.some(
      (registry) => String(registry.id) === defaults.registry_id,
    );

  async function save(values) {
    setSaving(true);
    try {
      await updateContainerSettings(application.id, {
        ...values,
        memory_limit: values.memory_limit === "" ? null : values.memory_limit,
        // Empty means "no quota", and it has to reach the API as null rather than
        // as "" — otherwise a limit once set could never be taken off again.
        cpu_limit: values.cpu_limit === "" ? null : values.cpu_limit,
        docker_network:
          values.docker_network === DEFAULT_NETWORK
            ? null
            : values.docker_network,
        registry_id:
          values.registry_id === NO_REGISTRY
            ? null
            : Number(values.registry_id),
      });
      // Before the toast: the Docker page's "used by" column and this site's own
      // facts are server-rendered, so a toast first uncovers page-load state.
      await refreshAndWait();
      toast.success(t("saved"));
      form.reset(values);
    } catch (error) {
      if (!handleValidationError(error, form)) {
        toast.error(apiMessage(error, t("failed")));
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <Card className={className}>
        <CardHeader>
          <CardTitle>
            {t("title")}
          </CardTitle>
        </CardHeader>
        <Form {...form}>
          <form noValidate onSubmit={form.handleSubmit(save)}>
            <CardContent className="space-y-4">
              <div className="space-y-1.5">
                <p className="text-xs font-medium text-muted-foreground">
                  {t("image")}
                </p>
                <code className="block truncate rounded-lg border bg-muted/40 px-3 py-2 font-mono text-xs">
                  {application.image || t("noImage")}
                </code>
                <p className="text-xs text-muted-foreground">{t("imageHint")}</p>
              </div>

              {/* Its own button, not part of Save. Pulling is not a settings
                  change: it downloads, recreates the container, and can take
                  minutes on a large image — batching it into a form that also
                  edits a port would hide all of that behind one click.

                  Here rather than on the deploy card because this is the deploy
                  story for a container: `compose up` reuses an image it already
                  has, so without this a site on a floating tag never moves. */}
              {canManage ? (
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3">
                  <div className="min-w-0">
                    <p className="text-sm font-medium">{t("pullTitle")}</p>
                    <p className="text-xs text-muted-foreground">
                      {t("pullHint")}
                    </p>
                  </div>
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={pulling}
                    onClick={async () => {
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
                    }}
                  >
                    <RefreshCw
                      className={pulling ? "size-4 animate-spin" : "size-4"}
                    />
                    {t("pull")}
                  </Button>
                </div>
              ) : null}

              <FormField
                control={form.control}
                name="registry_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t("registry")}</FormLabel>
                    <Select
                      value={field.value}
                      onValueChange={field.onChange}
                      disabled={!canManage}
                    >
                      <FormControl>
                        <SelectTrigger>
                          <SelectValue placeholder={t("noRegistry")} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={NO_REGISTRY}>
                          {t("noRegistry")}
                        </SelectItem>
                        {/* The site's own credential, kept in the list even if it is
                            gone — deleting a registry detaches the site rather than
                            blocking, so this option can legitimately name a row that
                            no longer exists. Dropping it would silently show
                            "anonymous" for a site whose next pull will fail. */}
                        {missingRegistry ? (
                          <SelectItem value={defaults.registry_id}>
                            {t("missingRegistryOption")}
                          </SelectItem>
                        ) : null}
                        {registries.map((registry) => (
                          <SelectItem
                            key={registry.id}
                            value={String(registry.id)}
                          >
                            {registry.name} — {registry.registry}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormDescription>{t("registryHint")}</FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />

              {registries.length === 0 ? (
                // An empty chooser with no explanation reads as a broken control —
                // the same reason the networks note below exists.
                <Note icon={KeyRound}>
                  {t("noRegistries")}{" "}
                  <Link
                    href="/integrations/registries"
                    className="font-medium text-primary underline"
                  >
                    {t("noRegistriesLink")}
                  </Link>
                </Note>
              ) : null}

              <FormField
                control={form.control}
                name="docker_network"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t("network")}</FormLabel>
                    <Select
                      value={field.value}
                      onValueChange={field.onChange}
                      disabled={!canManage}
                    >
                      <FormControl>
                        <SelectTrigger>
                          <SelectValue placeholder={t("defaultBridge")} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={DEFAULT_NETWORK}>
                          {t("defaultBridge")}
                        </SelectItem>
                        {missing ? (
                          <SelectItem value={defaults.docker_network}>
                            {t("missingOption", {
                              name: defaults.docker_network,
                            })}
                          </SelectItem>
                        ) : null}
                        {networks.map((network) => (
                          <SelectItem key={network.name} value={network.name}>
                            {network.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormDescription>{t("networkHint")}</FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />

              {networks.length === 0 ? (
                // An empty chooser with no explanation reads as a broken control.
                <Note icon={Network}>
                  {t("noNetworks")}{" "}
                  <Link
                    href="/docker"
                    className="font-medium text-primary underline"
                  >
                    {t("noNetworksLink")}
                  </Link>
                </Note>
              ) : null}

              {missing ? (
                <Note icon={Network} title={t("missingTitle")}>
                  {t("missingBody", { name: defaults.docker_network })}
                </Note>
              ) : null}

              {/* The name to type, said out loud.

                  Compose also registers the service name — `app` in every file the
                  panel generates — so two sites on one network both answer to
                  `app` and Docker's DNS picks one at random. The slug alias is
                  unique, and a unique name nobody is told about is no better than
                  no unique name at all. */}
              {defaults.docker_network !== DEFAULT_NETWORK && !missing ? (
                <Note icon={Network} title={t("reachableTitle")}>
                  {t("reachableBody", { alias: application.slug })}
                </Note>
              ) : null}

              <div className="grid gap-4 sm:grid-cols-2">
                <FormField
                  control={form.control}
                  name="container_port"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>{t("containerPort")}</FormLabel>
                      <FormControl>
                        <Input
                          type="number"
                          inputMode="numeric"
                          disabled={!canManage}
                          {...field}
                        />
                      </FormControl>
                      <FormDescription>{t("containerPortHint")}</FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="memory_limit"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>{t("memoryLimit")}</FormLabel>
                      <FormControl>
                        <Input
                          placeholder={limits.defaultMemoryLimit ?? "512m"}
                          disabled={!canManage}
                          {...field}
                        />
                      </FormControl>
                      <FormDescription>
                        {limits.defaultMemoryLimit
                          ? t("memoryLimitHint", {
                              default: limits.defaultMemoryLimit,
                            })
                          : t("memoryLimitHintUnknown")}
                      </FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="cpu_limit"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>{t("cpuLimit")}</FormLabel>
                      <FormControl>
                        <Input
                          // The placeholder says what empty MEANS, because for this
                          // field empty is not "the default" — it is no limit at
                          // all, which is the opposite of what the field beside it
                          // does with an empty value.
                          placeholder={t("cpuLimitPlaceholder")}
                          inputMode="decimal"
                          disabled={!canManage}
                          {...field}
                        />
                      </FormControl>
                      <FormDescription>
                        {/* The server's real core count, not a description of the
                            rule. "Up to the number of CPUs this server has" cannot
                            be acted on without leaving the page, and a hardcoded
                            number would be wrong on every box but one. Falls back
                            to the rule only when the box could not be asked — a
                            confident wrong number is worse than a vaguer right
                            one. */}
                        {limits.cpus
                          ? t("cpuLimitHint", { cores: limits.cpus })
                          : t("cpuLimitHintUnknown")}
                      </FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </div>

              {/* What the two numbers actually do, which is the part nobody knows
                  and the part that decides whether a limit was the right tool.

                  They fail in opposite ways: over the memory ceiling the kernel
                  kills the container and Docker restarts it, so the symptom is a
                  site that drops requests; over the CPU quota nothing is killed,
                  the container just waits, so the symptom is slowness with no
                  error anywhere. Somebody debugging one while thinking of the
                  other gets nowhere. */}
              <Note icon={Gauge} title={t("limitsTitle")}>
                {t("limitsBody")}
              </Note>

              {/* Its own control, not part of the form above. A mount is a discrete
                  fact that saves on add and remove — batching it into the Save
                  button would hide that each change recreates the container. */}
              <ContainerVolumes
                application={application}
                volumes={volumes}
                canManage={canManage}
              />

              {/* Only for a site the panel generated credentials for, and only for
                  somebody who can manage it — the endpoint is gated on `manage`, so
                  rendering the control for anyone else offers a button whose only
                  outcome is 403. */}
              {canManage ? (
                <ContainerCredentials application={application} />
              ) : null}
            </CardContent>
            <CardSaveFooter
              submit
              saving={saving}
              dirty={form.formState.isDirty}
              saveReason={
                !canManage
                  ? t("noPermission")
                  : !form.formState.isDirty
                    ? t("nothingToSave")
                    : null
              }
              onDiscard={() => form.reset(defaults)}
              saveLabel={t("saveAction")}
              // Said before the click, not discovered after it.
              note={t("restartNote")}
              savingNote={t("savingNote")}
              showReason
            />
          </form>
        </Form>
      </Card>
    </DisabledReasonProvider>
  );
}
