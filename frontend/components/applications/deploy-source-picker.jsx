import { useEffect, useId, useRef, useState } from "react";
import { useWatch } from "react-hook-form";
import { useTranslations } from "next-intl";
import { Container, FileCode2, PackageCheck, PenLine, Search } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { ChoiceField } from "@/components/ui/choice-field";
import { setDockerSource } from "@/lib/docker/create-request";
import { SiteTypePicker } from "@/components/applications/site-type-picker";
import { RecipeSummary } from "@/components/applications/recipe-summary";

const TAB =
  "h-auto min-w-0 flex-col items-start justify-start gap-0.5 whitespace-normal px-3 py-2.5 text-left data-active:bg-card data-active:ring-1 data-active:ring-primary/60 data-active:[&_svg]:text-primary";

export function DeploySourcePicker({ siteTypes, value, onChange, form, initialSource }) {
  const t = useTranslations("applications");
  const tabId = useId();
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
    if (isRecipe) lastRecipe.current = value;
    if (value !== "docker" && !isRecipe) return;
    // eslint-disable-next-line react-hooks/set-state-in-effect -- Sync external recipe selection with the controlled tab.
    setTab(value === "docker" ? "docker" : "recipes");
  }, [value, isRecipe]);

  function changeTab(next) {
    if (next === "docker" && !dockerType) return;
    setTab(next);
    if (next === "docker") setDockerSource(form, dockerSource);
    onChange(next === "docker" ? dockerType.name : (lastRecipe.current ?? ""));
  }

  function chooseRecipe(slug) {
    if (slug) lastRecipe.current = slug;
    onChange(slug);
  }

  function searchDocker(query) {
    if (!dockerType) return;
    for (const [name, value] of Object.entries({
      image: "", container_port: "", docker_image_state: "",
      docker_volumes: [], docker_env: [], docker_required_env: [],
    })) form.setValue(name, value, { shouldDirty: true });
    form.clearErrors(["image", "container_port", "docker_env", "docker_env_list", "docker_volumes", "docker_volumes_list"]);
    setDockerSource(form, "search");
    form.setValue("docker_search_query", query);
    setTab("docker");
    onChange(dockerType.name);
    requestAnimationFrame(() => dockerTrigger.current?.focus());
  }

  return (
    <Tabs value={tab} onValueChange={changeTab} className="min-w-0 gap-3">
      <TabsList className="grid w-full grid-cols-2 items-stretch gap-1 p-1 group-data-horizontal/tabs:h-auto">
        <TabsTrigger value="recipes" className={TAB} aria-labelledby={`${tabId}-recipes`} aria-describedby={`${tabId}-recipes-hint`}>
          <span id={`${tabId}-recipes`} className="flex items-center gap-2 text-sm font-semibold">
            <PackageCheck className="size-4" aria-hidden />{t("recipes.tabOneClick")}
          </span>
          <span id={`${tabId}-recipes-hint`} className="text-xs font-normal text-muted-foreground">{t("recipes.tabOneClickShort")}</span>
        </TabsTrigger>
        <TabsTrigger ref={dockerTrigger} value="docker" className={TAB} aria-labelledby={`${tabId}-docker`} aria-describedby={`${tabId}-docker-hint`}>
          <span id={`${tabId}-docker`} className="flex items-center gap-2 text-sm font-semibold">
            <Container className="size-4" aria-hidden />{t("recipes.tabDocker")}
          </span>
          <span id={`${tabId}-docker-hint`} className="text-xs font-normal text-muted-foreground">{t("recipes.tabDockerShort")}</span>
        </TabsTrigger>
      </TabsList>
      <TabsContent value="recipes" className="min-w-0 space-y-3">
        <SiteTypePicker
          types={recipeTypes}
          value={value === "docker" ? "" : value}
          onChange={chooseRecipe}
          searchPlaceholder={t("recipes.searchPlaceholder")}
          renderNoMatch={(query) => (
            <div className="rounded-xl border border-dashed px-3 py-8 text-center">
              <p className="text-sm wrap-anywhere text-muted-foreground">{t("recipes.noMatch", { query })}</p>
              <Button type="button" variant="outline" size="sm" className="mt-3 h-auto max-w-full py-1.5 whitespace-normal wrap-anywhere" onClick={() => searchDocker(query)}>
                {t("recipes.searchDockerInstead", { query })}
              </Button>
            </div>
          )}
        />
        {selected ? <RecipeSummary type={selected} /> : null}
      </TabsContent>
      <TabsContent value="docker" className="min-w-0 space-y-3">
        <div className="@container">
        <ChoiceField
          variant="card"
          aria-label={t("dockerModes.label")}
          className="grid gap-2 @3xl:grid-cols-3"
          value={dockerSource}
          onChange={(source) => { if (source) setDockerSource(form, source); }}
          options={[
            { value: "search", label: t("dockerModes.search"), hint: t("dockerModes.searchHint"), icon: Search },
            { value: "compose", label: t("dockerModes.compose"), hint: t("dockerModes.composeHint"), icon: FileCode2 },
            { value: "manual", label: t("dockerModes.manual"), hint: t("dockerModes.manualHint"), icon: PenLine },
          ]}
        />
        </div>
      </TabsContent>
    </Tabs>
  );
}
