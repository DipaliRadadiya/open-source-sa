import { useEffect, useId, useMemo, useRef, useState } from "react";
import { useWatch } from "react-hook-form";
import { useFormatter, useTranslations } from "next-intl";
import {
  BadgeCheck,
  ChevronDown,
  FileCode2,
  HardDrive,
  KeyRound,
  Loader2,
  Network,
  Plus,
  Search,
  SlidersHorizontal,
  Star,
  Trash2,
  X,
} from "lucide-react";
import {
  getDockerImageTags,
  inspectDockerImage,
  searchDockerImages,
} from "@/lib/api/docker";
import {
  imageInspectResponseSchema,
  imageSearchResponseSchema,
  imageTagsResponseSchema,
} from "@/lib/schemas/docker";
import {
  joinImageRef,
  looksLikeImageRef,
  splitImageRef,
  volumeNameFor,
} from "@/lib/docker/image-ref";
import { DOCKER_LIMITS, envRowProblem } from "@/lib/docker/create-request";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Caution } from "@/components/ui/caution";
import { Checkbox } from "@/components/ui/checkbox";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Note } from "@/components/ui/note";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";

const EXAMPLES = ["memos", "uptime-kuma", "nginx"];
const NO_REGISTRY = "__none__";
// Set by the base image or the build, never a setting of the app itself.
const SYSTEM_ENV = /^(PATH|HOME|HOSTNAME|TERM|LANG|LANGUAGE|LC_[A-Z]+|SHLVL|PWD|GPG_KEYS?|[A-Z0-9_]+_(VERSION|SHA256|SHA512|CHECKSUM|GPG_KEY))$/;

// "unavailable" covers a panel without the discovery endpoints and an error alike:
// in both cases the honest answer is "type it yourself".
function requestState(error) {
  if (error?.name === "CanceledError" || error?.code === "ERR_CANCELED") return null;
  return "unavailable";
}

function envRowsFrom(inspection) {
  return inspection.env
    .filter((item) => !SYSTEM_ENV.test(item.key))
    .map((item) => ({
      id: `image-${item.key}`,
      key: item.key,
      value: item.default ?? "",
      original: item.default ?? "",
      required: item.required,
      fromImage: true,
    }));
}

function volumeRowsFrom(inspection) {
  const declared = inspection.suggested_volumes.length
    ? inspection.suggested_volumes.map((volume) => volume.path)
    : inspection.volumes;
  return [...new Set(declared)].map((path) => ({ path, checked: true }));
}

/**
 * Simple-mode Docker: find an image, pick its version, and review what the
 * image itself declares (port, folders to keep, settings) instead of typing it.
 *
 * Writes `image`, `container_port` and `registry_id`, plus `docker_volumes`,
 * `docker_env`, `docker_required_env` and `docker_image_state`, which the create
 * form turns into the request.
 */
export function DockerImageSetup({ form, registryOptions = [], onUseCompose }) {
  const t = useTranslations("applications.dockerImage");
  const format = useFormatter();
  const listId = useId();
  const versionId = useId();
  const image = useWatch({ control: form.control, name: "image" }) ?? "";
  const registryId = useWatch({ control: form.control, name: "registry_id" }) ?? "";
  const appName = useWatch({ control: form.control, name: "name" }) ?? "";
  const volumes = useWatch({ control: form.control, name: "docker_volumes" }) ?? [];
  const envRows = useWatch({ control: form.control, name: "docker_env" }) ?? [];
  const errors = form.formState.errors;

  const initial = splitImageRef(image);
  const [repository, setRepository] = useState(initial.repository);
  const [query, setQuery] = useState(initial.repository);
  // Tagged with its query: an answer for an earlier query is never shown for this one.
  const [search, setSearch] = useState({ query: "", state: "idle", results: [] });
  const [active, setActive] = useState(-1);
  const [tags, setTags] = useState({ state: "idle", list: [], recommended: "" });
  const [tag, setTag] = useState(initial.tag);
  const [inspection, setInspection] = useState({ state: "idle", data: null });
  const [portEditing, setPortEditing] = useState(false);
  const [registryOpen, setRegistryOpen] = useState(Boolean(registryId));
  const inputRef = useRef(null);

  const chosen = Boolean(repository);
  const typed = query.trim();
  const answered = search.query === typed;
  const searchState = answered ? search.state : "loading";
  const searchResults = useMemo(() => (answered ? search.results : []), [answered, search.results]);

  // Search as the user types, only while nothing is chosen.
  useEffect(() => {
    if (chosen || typed.length < 2) return undefined;
    const controller = new AbortController();
    const timer = setTimeout(() => {
      searchDockerImages(typed, { signal: controller.signal })
        .then(({ data }) => {
          const parsed = imageSearchResponseSchema.safeParse(data);
          if (!parsed.success) return setSearch({ query: typed, state: "unavailable", results: [] });
          setSearch({
            query: typed,
            state: parsed.data.offline ? "offline" : parsed.data.results.length ? "ready" : "empty",
            results: parsed.data.results,
          });
          setActive(-1);
        })
        .catch((error) => {
          const state = requestState(error);
          if (state) setSearch({ query: typed, state, results: [] });
        });
    }, 300);
    return () => {
      controller.abort();
      clearTimeout(timer);
    };
  }, [chosen, typed]);

  // Versions for the chosen image; the recommended one is preselected unless the
  // user already named a tag.
  useEffect(() => {
    if (!repository) return undefined;
    const controller = new AbortController();
    const timer = setTimeout(() => {
      setTags({ state: "loading", list: [], recommended: "" });
      getDockerImageTags(repository, { signal: controller.signal })
        .then(({ data }) => {
          const parsed = imageTagsResponseSchema.safeParse(data);
          if (!parsed.success || !parsed.data.tags.length) {
            setTags({ state: "unavailable", list: [], recommended: "" });
            setTag((current) => current || "latest");
            return;
          }
          const recommended = parsed.data.recommended ?? parsed.data.tags[0].name;
          setTags({ state: "ready", list: parsed.data.tags, recommended });
          setTag((current) => current || recommended);
        })
        .catch((error) => {
          if (!requestState(error)) return;
          setTags({ state: "unavailable", list: [], recommended: "" });
          setTag((current) => current || "latest");
        });
    }, 0);
    return () => {
      controller.abort();
      clearTimeout(timer);
    };
  }, [repository]);

  const reference = repository && tag ? joinImageRef(repository, tag) : "";

  // Keep the form's `image` in step with what is chosen.
  useEffect(() => {
    if (form.getValues("image") === reference) return;
    form.setValue("image", reference, { shouldDirty: true, shouldValidate: Boolean(reference) });
  }, [form, reference]);

  // Read the image's declared port, folders and settings once a version is set.
  useEffect(() => {
    if (!reference) {
      form.setValue("docker_image_state", "", { shouldDirty: false });
      return undefined;
    }
    const controller = new AbortController();
    const timer = setTimeout(() => {
      setInspection({ state: "loading", data: null });
      setPortEditing(false);
      form.setValue("docker_image_state", "checking", { shouldDirty: false });
      inspectDockerImage(reference, {
        registryId: registryId && registryId !== NO_REGISTRY ? registryId : undefined,
        signal: controller.signal,
      })
        .then(({ data }) => {
          const parsed = imageInspectResponseSchema.safeParse(data);
          if (!parsed.success) throw new Error("Invalid inspect response");
          const result = parsed.data;
          if (!result.found) {
            setInspection({ state: "notfound", data: result });
            form.setValue("docker_image_state", "notfound", { shouldDirty: false });
            return;
          }
          setInspection({ state: "ready", data: result });
          form.setValue("docker_image_state", "ok", { shouldDirty: false });
          form.setValue(
            "container_port",
            result.port_confidence === "none" || !result.suggested_port ? "" : String(result.suggested_port),
            { shouldDirty: true, shouldValidate: false },
          );
          form.setValue("docker_volumes", volumeRowsFrom(result), { shouldDirty: true });
          const rows = envRowsFrom(result);
          form.setValue("docker_env", rows, { shouldDirty: true });
          form.setValue(
            "docker_required_env",
            rows.filter((row) => row.required).map((row) => row.key),
            { shouldDirty: false },
          );
        })
        .catch((error) => {
          if (!requestState(error)) return;
          // Not a verdict on the image: we could not ask. The user supplies the port.
          setInspection({ state: "unavailable", data: null });
          form.setValue("docker_image_state", "unknown", { shouldDirty: false });
          form.setValue("container_port", "", { shouldDirty: true });
          form.setValue("docker_volumes", [], { shouldDirty: true });
          form.setValue("docker_env", [], { shouldDirty: true });
          form.setValue("docker_required_env", [], { shouldDirty: false });
        });
    }, 0);
    return () => {
      controller.abort();
      clearTimeout(timer);
    };
  }, [form, reference, registryId]);

  function choose(value) {
    const { repository: repo, tag: typedTag } = splitImageRef(value);
    if (!repo || !looksLikeImageRef(value) || value.length > DOCKER_LIMITS.image) return;
    setQuery(repo);
    setTag(typedTag);
    setRepository(repo);
    form.clearErrors("image");
  }

  function clearChoice() {
    setRepository("");
    setTag("");
    setTags({ state: "idle", list: [], recommended: "" });
    setInspection({ state: "idle", data: null });
    form.setValue("container_port", "", { shouldDirty: true });
    form.setValue("docker_volumes", [], { shouldDirty: true });
    form.setValue("docker_env", [], { shouldDirty: true });
    form.setValue("docker_required_env", [], { shouldDirty: false });
    form.clearErrors(["docker_env", "docker_env_list", "docker_volumes", "docker_volumes_list"]);
    requestAnimationFrame(() => inputRef.current?.focus());
  }

  // A pasted reference is offered as its own row, ahead of the matches.
  const showTyped =
    looksLikeImageRef(typed) &&
    typed.length <= DOCKER_LIMITS.image &&
    !searchResults.some((result) => result.image === typed) &&
    (/[/:.@]/.test(typed) || ["offline", "unavailable", "empty"].includes(searchState));
  const options = useMemo(
    () => [
      ...(showTyped ? [{ image: typed, typed: true }] : []),
      ...(searchState === "ready" ? searchResults : []),
    ],
    [showTyped, typed, searchState, searchResults],
  );
  const listOpen = !chosen && typed.length >= 2;

  function onSearchKeyDown(event) {
    if (!listOpen || !options.length) {
      if (event.key === "Enter") event.preventDefault();
      return;
    }
    if (event.key === "ArrowDown") {
      event.preventDefault();
      setActive((index) => (index + 1) % options.length);
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      setActive((index) => (index <= 0 ? options.length - 1 : index - 1));
    } else if (event.key === "Enter") {
      event.preventDefault();
      choose(options[Math.max(active, 0)].image);
    }
  }

  const detected = inspection.data;
  const port = useWatch({ control: form.control, name: "container_port" });
  const portAsked = inspection.state === "unavailable" || (inspection.state === "ready" && detected?.port_confidence === "none");
  const portDiffers =
    detected?.suggested_port && String(port ?? "") !== "" && Number(port) !== detected.suggested_port;
  const otherPorts = (detected?.exposed_ports ?? []).filter((item) => item !== detected?.suggested_port);
  // Server errors placed by the create form (see dockerServerErrors).
  const envListError = errors.docker_env_list?.message;
  const volumeListError = errors.docker_volumes_list?.message;
  const checkedVolumes = volumes.filter((volume) => volume.checked).length;
  const envFull = envRows.length >= DOCKER_LIMITS.envRows;

  function updateEnv(index, patch) {
    const next = envRows.map((row, i) => (i === index ? { ...row, ...patch } : row));
    form.setValue("docker_env", next, { shouldDirty: true });
    form.clearErrors([`docker_env.${index}`, "docker_env_list"]);
  }

  // A setting the image requires stays: without it the app crash-loops on start.
  function removeEnv(index) {
    const row = envRows[index];
    if (!row || (row.fromImage && row.required)) return;
    form.setValue(
      "docker_env",
      envRows.filter((_, i) => i !== index),
      { shouldDirty: true },
    );
    // Row errors are by position, which just shifted.
    form.clearErrors(["docker_env", "docker_env_list"]);
  }

  function addEnv() {
    if (envFull) return;
    form.setValue(
      "docker_env",
      [...envRows, { id: `user-${Date.now()}`, key: "", value: "", original: "", required: false, fromImage: false }],
      { shouldDirty: true },
    );
  }

  const usedNames = [];
  const volumeName = (path) => {
    const name = volumeNameFor(appName, path, usedNames);
    usedNames.push(name);
    return name;
  };

  return (
    <div className="space-y-5">
      {/* 1. The image */}
      <FormField
        control={form.control}
        name="image"
        render={() => (
          <FormItem data-field-name="image" className="min-w-0">
            <FormLabel required hint={t("imageHint")}>{t("imageLabel")}</FormLabel>
            {chosen ? (
              <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/30 px-3 py-2">
                <BadgeCheck className="size-4 shrink-0 text-success" aria-hidden />
                <span className="min-w-0 break-all font-mono text-sm">{repository}</span>
                <Button type="button" variant="ghost" size="sm" className="ms-auto" onClick={clearChoice}>
                  <X className="size-3.5" aria-hidden />
                  {t("changeImage")}
                </Button>
              </div>
            ) : (
              <div className="space-y-2">
                <div className="relative">
                  <Search className="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                  <FormControl>
                    <Input
                      ref={inputRef}
                      role="combobox"
                      aria-expanded={listOpen}
                      aria-controls={listId}
                      aria-autocomplete="list"
                      aria-activedescendant={active >= 0 ? `${listId}-${active}` : undefined}
                      autoComplete="off"
                      spellCheck={false}
                      className="ps-8"
                      placeholder={t("searchPlaceholder")}
                      maxLength={DOCKER_LIMITS.image}
                      value={query}
                      onChange={(event) => setQuery(event.target.value)}
                      onKeyDown={onSearchKeyDown}
                    />
                  </FormControl>
                </div>
                {listOpen ? (
                  <div className="rounded-lg border bg-card">
                    {/* Announced as it changes: a screen reader is otherwise never told results arrived. */}
                    <div role="status" aria-live="polite" className={cn(options.length && "[&>p:not(.sr-only)]:border-b")}>
                      {searchState === "loading" ? (
                        <p className="flex items-center gap-2 px-3 py-2.5 text-sm text-muted-foreground">
                          <Loader2 className="size-3.5 animate-spin" aria-hidden />
                          {t("searching")}
                        </p>
                      ) : searchState === "empty" ? (
                        <p className="px-3 py-2.5 text-sm text-muted-foreground">{t("noResults", { query: typed })}</p>
                      ) : searchState === "offline" ? (
                        <p className="px-3 py-2.5 text-sm text-muted-foreground">{t("offline")}</p>
                      ) : searchState === "unavailable" ? (
                        <p className="px-3 py-2.5 text-sm text-muted-foreground">{t("searchUnavailable")}</p>
                      ) : searchState === "ready" ? (
                        <p className="sr-only">{t("resultsFound", { count: searchResults.length })}</p>
                      ) : null}
                    </div>
                    <ul id={listId} role="listbox" aria-label={t("resultsLabel")} className="max-h-80 overflow-y-auto">
                      {options.map((option, index) => (
                        <li
                          key={`${option.typed ? "typed" : "hit"}-${option.image}`}
                          id={`${listId}-${index}`}
                          role="option"
                          aria-selected={index === active}
                          onMouseDown={(event) => event.preventDefault()}
                          onClick={() => choose(option.image)}
                          onMouseEnter={() => setActive(index)}
                          className={cn(
                            "flex cursor-pointer items-start gap-3 border-b px-3 py-2.5 last:border-b-0",
                            index === active && "bg-muted/60",
                          )}
                        >
                          {option.typed ? (
                            <p className="min-w-0 text-sm">
                              {t.rich("useTyped", {
                                image: option.image,
                                mono: (chunks) => <span className="break-all font-mono">{chunks}</span>,
                              })}
                            </p>
                          ) : (
                            <div className="min-w-0 flex-1 space-y-0.5">
                              <div className="flex flex-wrap items-center gap-1.5">
                                <span className="break-all font-mono text-sm font-medium">{option.image}</span>
                                {option.official ? <Badge variant="success">{t("official")}</Badge> : null}
                                {option.verified_publisher ? <Badge>{t("verified")}</Badge> : null}
                              </div>
                              {option.description ? (
                                <p className="line-clamp-2 text-xs text-muted-foreground">{option.description}</p>
                              ) : null}
                              <p className="flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                                {option.stars != null ? (
                                  <span className="inline-flex items-center gap-1">
                                    <Star className="size-3" aria-hidden />
                                    {t("stars", { count: format.number(option.stars, { notation: "compact" }) })}
                                  </span>
                                ) : null}
                                {option.pulls != null ? (
                                  <span>{t("pulls", { count: format.number(option.pulls, { notation: "compact" }) })}</span>
                                ) : null}
                              </p>
                            </div>
                          )}
                        </li>
                      ))}
                    </ul>
                  </div>
                ) : (
                  <FormDescription className="flex flex-wrap items-center gap-x-2 gap-y-1.5">
                    <span>{t("examplesLead")}</span>
                    {EXAMPLES.map((example) => (
                      <Button
                        key={example}
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-6 px-2 font-mono text-xs"
                        onClick={() => setQuery(example)}
                      >
                        {example}
                      </Button>
                    ))}
                  </FormDescription>
                )}
              </div>
            )}
            <FormMessage />
          </FormItem>
        )}
      />

      {/* 2. The version */}
      {chosen ? (
        <div data-field-name="image_tag" className="space-y-2">
          <Label htmlFor={versionId} hint={t("versionHint")}>{t("versionLabel")}</Label>
          {tags.state === "loading" ? (
            <Skeleton className="h-9 w-full sm:w-72" />
          ) : tags.state === "ready" ? (
            <Select value={tag} onValueChange={setTag}>
              <SelectTrigger id={versionId} className="w-full sm:w-72">
                <SelectValue />
              </SelectTrigger>
              <SelectContent className="max-h-64">
                {/* A typed tag the registry did not list is still offered. */}
                {[...(tags.list.some((item) => item.name === tag) || !tag ? [] : [{ name: tag }]), ...tags.list].map((item) => (
                  <SelectItem key={item.name} value={item.name}>
                    <span className="font-mono">{item.name}</span>
                    {item.name === tags.recommended ? (
                      <Badge variant="success" className="ms-2">{t("recommended")}</Badge>
                    ) : null}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          ) : (
            <Input
              id={versionId}
              className="w-full font-mono sm:w-72"
              value={tag}
              maxLength={128}
              spellCheck={false}
              onChange={(event) => setTag(event.target.value.trim())}
            />
          )}
          <p className="text-sm text-muted-foreground">
            {tag === "latest" ? t("latestWarning") : t("versionHelp")}
          </p>
        </div>
      ) : null}

      {/* 3. What the image declares */}
      {chosen && reference ? (
        <section aria-labelledby="docker-detected-heading" className="space-y-4 rounded-lg border p-4">
          <div>
            <h3 id="docker-detected-heading" className="font-medium">{t("detectedTitle")}</h3>
            <p className="text-sm text-muted-foreground">
              {t.rich("detectedHint", {
                image: reference,
                mono: (chunks) => <span className="break-all font-mono">{chunks}</span>,
              })}
            </p>
          </div>

          {inspection.state === "loading" ? (
            <div className="space-y-2" aria-busy="true">
              <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="size-3.5 animate-spin" aria-hidden />
                {t("reading")}
              </p>
              <Skeleton className="h-5 w-48" />
              <Skeleton className="h-5 w-64" />
            </div>
          ) : inspection.state === "notfound" ? (
            <Caution tone="destructive" size="md">
              <p className="font-medium">{t("notFoundTitle")}</p>
              <p>{detected?.message || t("notFoundBody")}</p>
            </Caution>
          ) : (
            <>
              {inspection.state === "unavailable" ? (
                <Note title={t("inspectUnavailableTitle")}>{t("inspectUnavailableBody")}</Note>
              ) : null}

              {/* Port */}
              <FormField
                control={form.control}
                name="container_port"
                render={({ field }) => (
                  <FormItem data-field-name="container_port" className="space-y-1.5">
                    <div className="flex items-center gap-2">
                      <Network className="size-4 text-muted-foreground" aria-hidden />
                      <FormLabel hint={t("portHint")} required={portAsked}>{t("portLabel")}</FormLabel>
                    </div>
                    {portAsked || portEditing ? (
                      <>
                        <FormControl>
                          <Input
                            type="number"
                            inputMode="numeric"
                            className="w-full sm:w-40"
                            placeholder="8080"
                            {...field}
                            value={field.value ?? ""}
                          />
                        </FormControl>
                        {portAsked ? (
                          <FormDescription>{t("portAsk")}</FormDescription>
                        ) : (
                          <Caution>
                            <span>{t("portChangeWarning", { port: detected?.suggested_port })}</span>{" "}
                            <Button
                              type="button"
                              variant="link"
                              className="h-auto p-0 text-xs"
                              onClick={() => {
                                field.onChange(String(detected?.suggested_port ?? ""));
                                setPortEditing(false);
                              }}
                            >
                              {t("portUseDetected", { port: detected?.suggested_port })}
                            </Button>
                          </Caution>
                        )}
                      </>
                    ) : (
                      <p className="flex flex-wrap items-baseline gap-x-2 text-sm">
                        <span className="font-mono text-base font-medium">{field.value}</span>
                        <span className="text-muted-foreground">
                          {detected?.port_confidence === "guessed" ? t("portGuessed") : t("portDetected")}
                        </span>
                        <Button
                          type="button"
                          variant="link"
                          className="h-auto p-0 text-sm"
                          onClick={() => setPortEditing(true)}
                        >
                          {t("portChange")}
                        </Button>
                      </p>
                    )}
                    {otherPorts.length && !portAsked ? (
                      <FormDescription>{t("portOthers", { ports: otherPorts.join(", ") })}</FormDescription>
                    ) : null}
                    {portDiffers && !portEditing ? (
                      <FormDescription className="text-warning">
                        {t("portDiffers", { port: detected.suggested_port })}
                      </FormDescription>
                    ) : null}
                    <FormMessage />
                  </FormItem>
                )}
              />

              {/* Storage */}
              {inspection.state === "ready" ? (
                <div className="space-y-2" data-field-name="docker_volumes">
                  <p className="flex items-center gap-2 text-sm font-medium">
                    <HardDrive className="size-4 text-muted-foreground" aria-hidden />
                    {t("storageLabel")}
                  </p>
                  {volumes.length ? (
                    <ul className="space-y-2">
                      {volumes.map((volume, index) => {
                        const id = `docker-volume-${index}`;
                        // Named in the same order as the request, so the label is the volume created.
                        const name = volume.checked ? volumeName(volume.path) : null;
                        const rowError = errors.docker_volumes?.[index]?.message;
                        return (
                          <li key={volume.path} className="flex items-start gap-2.5">
                            <Checkbox
                              id={id}
                              className="mt-0.5"
                              checked={volume.checked}
                              aria-invalid={rowError ? true : undefined}
                              aria-describedby={rowError ? `${id}-error` : undefined}
                              onCheckedChange={(checked) => {
                                form.setValue(
                                  "docker_volumes",
                                  volumes.map((item, i) => (i === index ? { ...item, checked: Boolean(checked) } : item)),
                                  { shouldDirty: true },
                                );
                                form.clearErrors([`docker_volumes.${index}`, "docker_volumes_list"]);
                              }}
                            />
                            <label htmlFor={id} className="min-w-0 text-sm">
                              {t.rich("storageKeep", {
                                path: volume.path,
                                mono: (chunks) => <span className="break-all font-mono">{chunks}</span>,
                              })}
                              <span className="block text-xs text-muted-foreground">
                                {volume.checked
                                  ? t("storageVolume", { name })
                                  : t("storageOff")}
                              </span>
                              {rowError ? (
                                <span id={`${id}-error`} className="block text-xs text-destructive">{rowError}</span>
                              ) : null}
                            </label>
                          </li>
                        );
                      })}
                    </ul>
                  ) : (
                    <p className="text-sm text-muted-foreground">{t("storageNone")}</p>
                  )}
                  {checkedVolumes > DOCKER_LIMITS.volumes ? (
                    <p className="text-xs text-destructive">{t("storageLimit", { max: DOCKER_LIMITS.volumes })}</p>
                  ) : null}
                  {volumeListError ? (
                    <p className="text-xs text-destructive" data-form-error>{volumeListError}</p>
                  ) : null}
                </div>
              ) : null}

              {/* Settings (environment variables) */}
              {inspection.state === "ready" || envRows.length ? (
                <div className="space-y-2" data-field-name="docker_env">
                  <p className="flex items-center gap-2 text-sm font-medium">
                    <SlidersHorizontal className="size-4 text-muted-foreground" aria-hidden />
                    <span>{t("settingsLabel")}</span>
                  </p>
                  <p className="text-sm text-muted-foreground">{t("settingsHint")}</p>
                  {envRows.length ? (
                    <ul className="space-y-2">
                      {envRows.map((row, index) => {
                        const problem = envRowProblem(row);
                        const missing = problem === "required";
                        const badKey = problem === "badKey";
                        const keyError = errors.docker_env?.[index]?.key?.message;
                        const valueError = errors.docker_env?.[index]?.value?.message;
                        const messageId = `${listId}-env-${index}`;
                        const describedBy = keyError || valueError || missing || badKey ? messageId : undefined;
                        const locked = row.fromImage && row.required;
                        return (
                          <li key={row.id} className="space-y-1">
                            {/* Phone: name and remove on one line, value below. Placed by `order`, not row/column starts, which leak across breakpoints. */}
                            <div className="grid grid-cols-[minmax(0,1fr)_auto] gap-2 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_auto]">
                              <Input
                                aria-label={t("envKey")}
                                placeholder={t("envKeyPlaceholder")}
                                className="order-1 font-mono text-xs"
                                spellCheck={false}
                                readOnly={row.fromImage}
                                maxLength={DOCKER_LIMITS.envKey}
                                aria-invalid={badKey || keyError ? true : undefined}
                                aria-describedby={badKey || keyError ? describedBy : undefined}
                                value={row.key}
                                onChange={(event) => updateEnv(index, { key: event.target.value })}
                              />
                              <Input
                                aria-label={t("envValueFor", { key: row.key || "…" })}
                                placeholder={row.required ? t("envRequiredPlaceholder") : t("envValuePlaceholder")}
                                className={cn("order-3 col-span-2 font-mono text-xs sm:order-2 sm:col-span-1", missing && "border-warning")}
                                spellCheck={false}
                                maxLength={DOCKER_LIMITS.envValue}
                                aria-invalid={missing || valueError ? true : undefined}
                                aria-describedby={missing || valueError ? describedBy : undefined}
                                value={row.value}
                                onChange={(event) => updateEnv(index, { value: event.target.value })}
                              />
                              {locked ? (
                                // Keeps the value column the width of its neighbours.
                                <span className="order-2 size-9 sm:order-3" aria-hidden />
                              ) : (
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="icon"
                                  className="order-2 sm:order-3"
                                  aria-label={t("envRemove", { key: row.key || "…" })}
                                  onClick={() => removeEnv(index)}
                                >
                                  <Trash2 className="size-4" aria-hidden />
                                </Button>
                              )}
                            </div>
                            {keyError || valueError ? (
                              <p id={messageId} className="text-xs text-destructive">{keyError || valueError}</p>
                            ) : missing ? (
                              <p id={messageId} className="text-xs text-warning">{t("envRequired")}</p>
                            ) : badKey ? (
                              <p id={messageId} className="text-xs text-destructive">{t("envKeyInvalid")}</p>
                            ) : null}
                          </li>
                        );
                      })}
                    </ul>
                  ) : (
                    <p className="text-sm text-muted-foreground">{t("settingsNone")}</p>
                  )}
                  {envListError ? (
                    <p className="text-xs text-destructive" data-form-error>{envListError}</p>
                  ) : null}
                  {envFull ? (
                    <p className="text-xs text-muted-foreground">{t("envLimit", { max: DOCKER_LIMITS.envRows })}</p>
                  ) : (
                    <Button type="button" variant="outline" size="sm" onClick={addEnv}>
                      <Plus className="size-3.5" aria-hidden />
                      {t("envAdd")}
                    </Button>
                  )}
                </div>
              ) : null}

              {detected?.warnings?.length ? (
                <Caution size="md">
                  <p className="font-medium">{t("warningsTitle")}</p>
                  <ul className="list-disc ps-4">
                    {detected.warnings.map((warning) => (
                      <li key={warning}>{warning}</li>
                    ))}
                  </ul>
                </Caution>
              ) : null}

            </>
          )}
        </section>
      ) : null}

      {/* Private registry: collapsed, it is the rare case. */}
      {registryOptions.length ? (
        <Collapsible open={registryOpen} onOpenChange={setRegistryOpen}>
          <CollapsibleTrigger asChild>
            <Button type="button" variant="ghost" size="sm" className="-ms-2 gap-1.5 text-muted-foreground">
              <KeyRound className="size-3.5" aria-hidden />
              {t("privateRegistry")}
              <ChevronDown className={cn("size-3.5 transition-transform", registryOpen && "rotate-180")} aria-hidden />
            </Button>
          </CollapsibleTrigger>
          <CollapsibleContent className="pt-2">
            <FormField
              control={form.control}
              name="registry_id"
              render={({ field }) => (
                <FormItem data-field-name="registry_id" className="max-w-md">
                  <Select
                    value={field.value ? String(field.value) : NO_REGISTRY}
                    onValueChange={(value) => field.onChange(value === NO_REGISTRY ? "" : value)}
                  >
                    <FormControl>
                      <SelectTrigger className="w-full" aria-label={t("privateRegistry")}>
                        <SelectValue />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      <SelectItem value={NO_REGISTRY}>{t("publicImage")}</SelectItem>
                      {registryOptions.map((option) => (
                        <SelectItem key={option.value} value={String(option.value)}>
                          {option.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <FormDescription>{t("privateRegistryHint")}</FormDescription>
                  <FormMessage />
                </FormItem>
              )}
            />
          </CollapsibleContent>
        </Collapsible>
      ) : null}

      <p className="border-t pt-4 text-sm text-muted-foreground">
        <FileCode2 className="me-1.5 inline size-3.5 align-[-2px]" aria-hidden />
        {t("composeLead")}{" "}
        <Button type="button" variant="link" className="h-auto p-0 text-sm" onClick={onUseCompose}>
          {t("useCompose")}
        </Button>
      </p>
    </div>
  );
}
