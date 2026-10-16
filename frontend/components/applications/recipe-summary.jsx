import { SiteTypeLogo } from "@/components/applications/site-type-logo";
import { useTranslations } from "next-intl";
import { ChevronDown, ExternalLink } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { FACT_LABEL } from "@/lib/theme/facts";

// Explicit keys keep every first-run promise visible to check-i18n.
function firstRunText(kind, t) {
  switch (kind) {
    case "credentials": return t("recipes.firstRunCredentials");
    case "claimed": return t("recipes.firstRunClaimed");
    case "setup_wizard": return t("recipes.firstRunSetupWizard");
    case "open_registration": return t("recipes.firstRunOpenRegistration");
    case "default_login": return t("recipes.firstRunDefaultLogin");
    case "none": return t("recipes.firstRunNone");
    default: return null;
  }
}

export function RecipeSummary({ type }) {
  const t = useTranslations("applications");
  const recipe = type?.recipe;
  if (!recipe) return null;
  const afterInstall = firstRunText(recipe.first_run?.kind, t);

  return (
    // Framed like the required-services panel that shares this slot, not a card in a card.
    <section className="overflow-hidden rounded-xl border" aria-labelledby="recipe-summary-heading">
      <div className="border-b bg-muted/30 px-4 py-3">
        <h3 id="recipe-summary-heading" className="flex items-center gap-3 text-sm font-medium">
          <SiteTypeLogo name={type.name} iconDataUri={recipe.icon_data_uri} />
          {t("recipes.detailsTitle")}
        </h3>
      </div>
      <div className="space-y-3 p-4">
        {/* Label and value on one line from sm, as in the panel's other two-column lists. */}
        <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-[auto_minmax(0,1fr)] sm:items-baseline sm:gap-x-4 sm:gap-y-2">
          <dt className={FACT_LABEL}>{t("recipes.servicesLabel")}</dt>
          <dd className="min-w-0 break-words">{recipe.services.map((service) => service.name).join(", ")}</dd>
          <dt className={FACT_LABEL}>{t("recipes.storageLabel")}</dt>
          <dd className="min-w-0">
            {recipe.volumes.length ? (
              <ul className="space-y-1 font-mono text-xs">
                {recipe.volumes.map((volume) => <li key={volume.role} className="break-all">{volume.path}</li>)}
              </ul>
            ) : t("recipes.noStorage")}
          </dd>
          {afterInstall ? (
            <>
              <dt className={FACT_LABEL}>{t("recipes.firstRunLabel")}</dt>
              <dd className="min-w-0 leading-relaxed">{afterInstall}</dd>
            </>
          ) : null}
        </dl>
        {recipe.memory_floor ? <p className="text-xs text-muted-foreground">{t("recipes.memoryFloor", { memory: recipe.memory_floor })}</p> : null}
        {recipe.images.length ? (
          <Collapsible>
            <CollapsibleTrigger asChild>
              <Button type="button" variant="ghost" size="sm" className="group -ms-2 h-auto whitespace-normal py-1.5 text-start">
                {t("recipes.testedVersions")}
                <ChevronDown className="size-4 shrink-0 transition-transform group-data-[state=open]:rotate-180" aria-hidden />
              </Button>
            </CollapsibleTrigger>
            <CollapsibleContent>
              <ul className="space-y-2 pt-2 font-mono text-xs">
                {recipe.images.map((image) => (
                  <li key={image.role} className="break-all">
                    {image.ref}
                    {image.tested.length ? <span className="text-muted-foreground"> ({image.tested.join(", ")})</span> : null}
                  </li>
                ))}
              </ul>
            </CollapsibleContent>
          </Collapsible>
        ) : null}
        {recipe.docs_url ? (
          <a href={recipe.docs_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 text-sm text-primary underline-offset-4 hover:underline">
            {t("recipes.docs")}<ExternalLink className="size-3.5" aria-hidden />
          </a>
        ) : null}
      </div>
    </section>
  );
}
