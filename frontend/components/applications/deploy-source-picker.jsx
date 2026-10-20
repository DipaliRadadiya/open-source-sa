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

// Title over a one-line subtitle; the active half is raised with the brand outline
// and a green icon. `h-auto`: the base trigger is sized for a single line.
const TAB =
  "h-auto min-w-0 flex-col items-start justify-start gap-0.5 whitespace-normal px-3 py-2.5 text-left data-active:bg-card data-active:ring-1 data-active:ring-primary/60 data-active:[&_svg]:text-primary";

export function DeploySourcePicker({ siteTypes, value, onChange, form, initialSource }) {
  const t = useTranslations("applications");
  // Each tab is named by its title; the subtitle is its description.
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
      {/* The first choice, so it reads as one: each half says what it gives you,
          and the chosen half is raised with the brand outline. The mode cards
          below are the second, smaller choice. */}
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
            // The picker's own no-result shape (dashed, centred), with the way out under it.
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
        {/* Cards, not a second row of tabs: each says what it is for, so the
            Container tab needs no paragraph above or below it. */}
        {/* Three across only where the column is wide enough; beside the review
            panel it is ~600px and three cards squeezed each hint to four lines. */}
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
