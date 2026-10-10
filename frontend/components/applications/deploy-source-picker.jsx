import { useEffect, useRef, useState } from "react";
import { useWatch } from "react-hook-form";
import { useTranslations } from "next-intl";
import { Container, FileCode2, PackageCheck, PenLine, Search } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Caution } from "@/components/ui/caution";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group";
import { setDockerSource } from "@/lib/docker/create-request";
import { SiteTypePicker } from "@/components/applications/site-type-picker";
import { RecipeSummary } from "@/components/applications/recipe-summary";

export function DeploySourcePicker({ siteTypes, value, onChange, form, initialSource }) {
  const t = useTranslations("applications");
  const recipeTypes = siteTypes.filter((type) => type.recipe);
  const dockerType = siteTypes.find((type) => type.name === "docker");
  const [tab, setTab] = useState(() =>
    value === "docker" || ["search", "compose", "manual"].includes(initialSource) ? "docker" : "recipes",
  );
  const lastRecipe = useRef(recipeTypes.some((type) => type.name === value) ? value : null);
  const selected = recipeTypes.find((type) => type.name === value);
  const dockerTrigger = useRef(null);
  const dockerSource = useWatch({ control: form.control, name: "docker_source" }) || "search";
  const isRecipe = Boolean(selected);

  useEffect(() => {
    // Image search can select a recipe outside this picker. Keep the tab and
    // its remembered recipe in step with that form field too.
    if (isRecipe) lastRecipe.current = value;
    if (value !== "docker" && !isRecipe) return;
    // eslint-disable-next-line react-hooks/set-state-in-effect -- Sync external recipe selection with the controlled tab.
    setTab(value === "docker" ? "docker" : "recipes");
  }, [value, isRecipe]);

  function changeTab(next) {
    if (next === "docker" && !dockerType) return;
    setTab(next);
    // Type changes unregister Docker's backend fields; restore its mode from
    // the UI choice before the type's generic simple default can be applied.
    if (next === "docker") setDockerSource(form, dockerSource);
    onChange(next === "docker" ? dockerType.name : (lastRecipe.current ?? ""));
  }

  function chooseRecipe(slug) {
    if (slug) lastRecipe.current = slug;
    onChange(slug);
  }

  function searchDocker(query) {
    if (!dockerType) return;
    // An explicit new search replaces the previous image, unlike a tab round-trip.
    // Without this, leaving Docker before choosing a recipe would restore that
    // image and hide the requested search when the Docker fields mount again.
    for (const [name, value] of Object.entries({
      image: "", container_port: "", docker_image_state: "",
      docker_volumes: [], docker_env: [], docker_required_env: [],
    })) form.setValue(name, value, { shouldDirty: true });
    form.clearErrors(["image", "container_port", "docker_env", "docker_env_list", "docker_volumes", "docker_volumes_list"]);
    // UI-only answers; the create request still uses the backend's type fields.
    setDockerSource(form, "search");
    form.setValue("docker_search_query", query);
    setTab("docker");
    onChange(dockerType.name);
    requestAnimationFrame(() => dockerTrigger.current?.focus());
  }

  return (
    <Tabs value={tab} onValueChange={changeTab} className="min-w-0 gap-3">
      <TabsList className="grid w-full grid-cols-2">
        <TabsTrigger value="recipes" className="min-w-0 whitespace-normal text-xs sm:text-sm">
          <PackageCheck className="size-4" aria-hidden />{t("recipes.tabOneClick")}
        </TabsTrigger>
        <TabsTrigger ref={dockerTrigger} value="docker">
          <Container className="size-4" aria-hidden />{t("recipes.tabDocker")}
        </TabsTrigger>
      </TabsList>
      <p className="text-sm text-muted-foreground">
        {tab === "recipes" ? t("recipes.tabOneClickHint") : t("recipes.tabDockerHint")}
      </p>
      <TabsContent value="recipes" className="min-w-0 space-y-3">
        <SiteTypePicker
          types={recipeTypes}
          value={value === "docker" ? "" : value}
          onChange={chooseRecipe}
          searchPlaceholder={t("recipes.searchPlaceholder")}
          renderNoMatch={(query) => (
            <Caution size="md">
              <p className="break-words">{t("recipes.noMatch", { query })}</p>
              <Button type="button" variant="outline" size="sm" className="h-auto max-w-full whitespace-normal break-all py-1.5 text-start" onClick={() => searchDocker(query)}>
                {t("recipes.searchDockerInstead", { query })}
              </Button>
            </Caution>
          )}
        />
        {selected ? <RecipeSummary type={selected} /> : null}
      </TabsContent>
      <TabsContent value="docker" className="min-w-0 space-y-3">
        <ToggleGroup
          type="single"
          variant="outline"
          className="grid w-full grid-cols-3 items-stretch"
          aria-label={t("dockerModes.label")}
          value={dockerSource}
          onValueChange={(source) => { if (source) setDockerSource(form, source); }}
        >
          <ToggleGroupItem value="search" className="h-auto min-w-0 flex-col gap-1 whitespace-normal py-2 text-xs sm:flex-row sm:text-sm">
            <Search className="size-4 shrink-0" aria-hidden />{t("dockerModes.search")}
          </ToggleGroupItem>
          <ToggleGroupItem value="compose" className="h-auto min-w-0 flex-col gap-1 whitespace-normal py-2 text-xs sm:flex-row sm:text-sm">
            <FileCode2 className="size-4 shrink-0" aria-hidden />{t("dockerModes.compose")}
          </ToggleGroupItem>
          <ToggleGroupItem value="manual" className="h-auto min-w-0 flex-col gap-1 whitespace-normal py-2 text-xs sm:flex-row sm:text-sm">
            <PenLine className="size-4 shrink-0" aria-hidden />{t("dockerModes.manual")}
          </ToggleGroupItem>
        </ToggleGroup>
        <p className="text-sm text-muted-foreground">
          {dockerSource === "compose" ? t("dockerModes.composeHint") : dockerSource === "manual" ? t("dockerModes.manualHint") : t("dockerModes.searchHint")}
        </p>
      </TabsContent>
    </Tabs>
  );
}
