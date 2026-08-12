"use client";

import { useTranslations } from "next-intl";
import { Server, AppWindow, Folder } from "lucide-react";
import {
  ACCESS_MANAGE,
  ACCESS_NONE,
  ACCESS_VIEW,
} from "@/lib/schemas/role";
import { Checkbox } from "@/components/ui/checkbox";

const LEVEL_ICONS = { server: Server, application: AppWindow };

function levelIcon(level) {
  return LEVEL_ICONS[level] || Folder;
}

export function permKey(level, name) {
  return `${level}:${name}`;
}

/**
 * Two checkboxes over one stored access level.
 *
 * The state saved is a single level — `none`, `view` or `manage` — because the
 * server stores three states, not four. The pair is only how it is EDITED:
 * both boxes visible at a glance beats a control you have to open to read, and
 * ticking through a list of thirty is the whole job here.
 *
 * Manage implies View, so ticking Manage ticks View and clearing View clears
 * Manage — the combination the server would rewrite is simply never reachable.
 */
function viewOf(access) {
  return access === ACCESS_VIEW || access === ACCESS_MANAGE;
}

function manageOf(access) {
  return access === ACCESS_MANAGE;
}

function withView(access, next) {
  if (next) return access === ACCESS_MANAGE ? ACCESS_MANAGE : ACCESS_VIEW;
  return ACCESS_NONE;
}

function withManage(access, next) {
  return next ? ACCESS_MANAGE : viewOf(access) ? ACCESS_VIEW : ACCESS_NONE;
}

// `checked` may be true, false, or "indeterminate" for a header covering rows
// that disagree.
function AccessCheck({ id, checked, onChange, label }) {
  return (
    <label
      htmlFor={id}
      className="inline-flex cursor-pointer items-center gap-1.5 text-xs font-medium text-foreground select-none"
    >
      <Checkbox id={id} checked={checked} onCheckedChange={(c) => onChange(c === true)} />
      {label}
    </label>
  );
}

function AccessToggles({ idBase, view, manage, onView, onManage, labels }) {
  return (
    <div className="flex shrink-0 items-center gap-4">
      <AccessCheck id={`${idBase}-view`} checked={view} onChange={onView} label={labels.view} />
      <AccessCheck
        id={`${idBase}-manage`}
        checked={manage}
        onChange={onManage}
        label={labels.manage}
      />
    </div>
  );
}

// true / false / "indeterminate" for a set of rows.
function tally(items, predicate) {
  if (items.every(predicate)) return true;
  return items.some(predicate) ? "indeterminate" : false;
}

export function PermissionMatrix({ groups = [], accessLevels = [], value, onChange }) {
  const t = useTranslations("roles.form");

  const accessFor = (item) => value[permKey(item.level, item.name)] ?? ACCESS_NONE;

  // Prefer the server's own words for the two levels these boxes represent,
  // so a locale change lands here too; the local strings stay as the fallback
  // for a backend that sends no catalog.
  const labels = {
    view: accessLevels.find((level) => level.key === ACCESS_VIEW)?.title ?? t("view"),
    manage: accessLevels.find((level) => level.key === ACCESS_MANAGE)?.title ?? t("manage"),
  };

  const allItems = groups.flatMap((group) => group.permissions);

  function setMany(items, next) {
    const updates = {};
    for (const item of items) updates[permKey(item.level, item.name)] = next(accessFor(item));
    onChange({ ...value, ...updates });
  }

  const permDesc = (name) => {
    const key = `permDesc.${name}`;
    const text = t(key);
    return text === key ? "" : text;
  };

  if (!allItems.length) {
    return <p className="text-sm text-muted-foreground">{t("noPermissions")}</p>;
  }

  return (
    <div className="space-y-4">
      {/* One control over everything, above the sections it governs. Granting a
          role read across the whole panel is a normal starting point, and doing
          it section by section is the same click thirty times. */}
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/40 px-3 py-2">
        <div className="min-w-0">
          <p className="text-sm font-semibold">{t("selectAll")}</p>
          <p className="text-xs text-muted-foreground">{t("legendLine")}</p>
        </div>
        <AccessToggles
          idBase="setall-everything"
          view={tally(allItems, (item) => viewOf(accessFor(item)))}
          manage={tally(allItems, (item) => manageOf(accessFor(item)))}
          onView={(next) => setMany(allItems, (access) => withView(access, next))}
          onManage={(next) => setMany(allItems, (access) => withManage(access, next))}
          labels={labels}
        />
      </div>

      {groups.map((group) => {
        const items = group.permissions;
        if (!items.length) return null;
        const LevelIcon = levelIcon(group.level);
        // Keyed on both, exactly as the server groups them: `logs` exists at
        // server and application level as two unrelated permissions.
        const sectionKey = `${group.level}|${group.sub_level}`;

        return (
          <div key={sectionKey} className="overflow-hidden rounded-lg border">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/40 px-3 py-2">
              <span className="flex items-center gap-2 text-sm font-semibold">
                <LevelIcon className="size-4 text-muted-foreground" />
                {group.sub_level_title || t("generalGroup")}
              </span>
              <div className="flex items-center gap-2">
                <span className="text-[11px] font-medium text-muted-foreground">
                  {t("setAll")}
                </span>
                <AccessToggles
                  idBase={`setall-${sectionKey}`}
                  view={tally(items, (item) => viewOf(accessFor(item)))}
                  manage={tally(items, (item) => manageOf(accessFor(item)))}
                  onView={(next) => setMany(items, (access) => withView(access, next))}
                  onManage={(next) => setMany(items, (access) => withManage(access, next))}
                  labels={labels}
                />
              </div>
            </div>

            <div className="divide-y">
              {items.map((item) => {
                const access = accessFor(item);
                const description = permDesc(item.name);
                return (
                  <div
                    key={permKey(item.level, item.name)}
                    className="flex flex-col gap-2 px-3 py-2.5 transition-colors hover:bg-muted/40 sm:flex-row sm:items-center sm:justify-between sm:gap-3"
                  >
                    <div className="min-w-0">
                      <p className="text-sm leading-tight font-medium">
                        {item.title || item.name}
                      </p>
                      {description ? (
                        <p className="mt-0.5 text-xs leading-snug text-muted-foreground sm:line-clamp-1">
                          {description}
                        </p>
                      ) : null}
                    </div>
                    <AccessToggles
                      idBase={permKey(item.level, item.name)}
                      view={viewOf(access)}
                      manage={manageOf(access)}
                      onView={(next) => setMany([item], (current) => withView(current, next))}
                      onManage={(next) => setMany([item], (current) => withManage(current, next))}
                      labels={labels}
                    />
                  </div>
                );
              })}
            </div>
          </div>
        );
      })}
    </div>
  );
}
