import { useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { useSetQuery } from "@/hooks/use-set-query";
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { SELECT_WELL } from "@/components/data-table/toolbar-well";
import { cn } from "@/lib/utils";

/**
 * The "whose backups" filter of History and Restores: applications, plus the databases
 * that are backed up on their own (RC-19/RC-23) when there are any. One control writing
 * `application` or `database`, never both, so the two cannot contradict each other.
 */
export function SubjectFilter({ applications = [], databases = [], allLabel, label, className }) {
  const t = useTranslations("backups.subjectFilter");
  const searchParams = useSearchParams();
  const setQuery = useSetQuery();

  const appOptions = applications.map((row) => ({ value: `application:${row.id}`, label: row.name }));
  const dbOptions = databases.map((row) => ({ value: `database:${row.id}`, label: row.name }));
  const options = [...appOptions, ...dbOptions];

  const database = searchParams.get("database");
  const application = searchParams.get("application");
  const raw = database ? `database:${database}` : application ? `application:${application}` : null;
  // Only a value offered here: Radix renders a blank trigger for an unknown one.
  const value = options.some((option) => option.value === raw) ? raw : "all";
  const everything = dbOptions.length ? t("all") : allLabel;

  function onChange(next) {
    const [kind, id] = next === "all" ? [] : next.split(":");
    setQuery(
      { application: kind === "application" ? id : undefined, database: kind === "database" ? id : undefined },
      { resetPage: true },
    );
  }

  const items = (list) =>
    list.map((option) => (
      <SelectItem key={option.value} value={option.value}>
        {option.label}
      </SelectItem>
    ));

  return (
    <Select value={value} onValueChange={onChange}>
      <SelectTrigger className={cn(SELECT_WELL, className)} aria-label={label}>
        <SelectValue>{value === "all" ? everything : (options.find((option) => option.value === value)?.label ?? everything)}</SelectValue>
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="all">{everything}</SelectItem>
        {dbOptions.length ? (
          <>
            <SelectGroup>
              <SelectLabel>{t("applications")}</SelectLabel>
              {items(appOptions)}
            </SelectGroup>
            <SelectGroup>
              <SelectLabel>{t("databases")}</SelectLabel>
              {items(dbOptions)}
            </SelectGroup>
          </>
        ) : (
          items(appOptions)
        )}
      </SelectContent>
    </Select>
  );
}
