import { getTranslations } from "next-intl/server";
import { CircleCheck, ShieldAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { getDoctor } from "@/lib/admin/get-doctor";
import { CheckRow } from "@/components/admin/doctor/check-row";
import { RecheckButton } from "@/components/admin/doctor/recheck-button";
import { CopyReportButton } from "@/components/admin/doctor/copy-report-button";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("doctor");
  return { title: t("title") };
}

// Needs-attention first: failures, then warnings, then the passing checks —
// so the eye lands on what to fix, mirroring the setup checklist.
const RANK = { fail: 0, warn: 1, pass: 2 };

export default async function AdminDoctorPage() {
  const [t, doctor] = await Promise.all([getTranslations("doctor"), getDoctor()]);

  const statusLabels = { pass: t("statusPass"), warn: t("statusWarn"), fail: t("statusFail") };

  // Ordered once, reused for both the rendered list and the copyable report.
  const sorted = doctor
    ? [...doctor.checks].sort((a, b) => (RANK[a.status] ?? 1) - (RANK[b.status] ?? 1))
    : [];
  const attention = sorted.filter((c) => c.status !== "pass");
  const passed = sorted.filter((c) => c.status === "pass");

  // Plain-text report for pasting to support: summary, then every check with its
  // evidence and (when not passing) its fix.
  const report = doctor
    ? [
        `${t("title")} — ${doctor.healthy ? t("healthy") : t("unhealthy", { count: doctor.failed })}`,
        t("counts", { passed: doctor.passed, warnings: doctor.warnings, failed: doctor.failed }),
        "",
        ...sorted.map((c) =>
          [
            `[${statusLabels[c.status]}] ${c.title}`,
            c.detail ? `  ${c.detail}` : null,
            c.status !== "pass" && c.fix ? `  ${t("fixLabel")}: ${c.fix}` : null,
          ]
            .filter(Boolean)
            .join("\n"),
        ),
      ].join("\n")
    : "";

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
          <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
        </div>
        {doctor ? (
          // flex-wrap, not shrink-0: both labels are verbs and grow in other
          // locales ("रिपोर्ट कॉपी करें" / "फिर से जाँचें"), and a group that
          // can neither shrink nor wrap pushed 20px off a 320px screen.
          <div className="flex flex-wrap gap-2">
            <CopyReportButton text={report} />
            <RecheckButton />
          </div>
        ) : null}
      </div>

      {!doctor ? (
        <LoadFailed description={t("loadFailed")} />
      ) : (
        <>
          {/* Summary: a NEUTRAL overview surface so it reads as the header of
              the list, not as another (tinted) check card. Status is carried by
              the coloured icon, not by tinting the whole card. */}
          <div className="flex flex-wrap items-center gap-4 rounded-2xl border bg-muted/40 p-4">
            <span
              className={cn(
                "flex size-11 shrink-0 items-center justify-center rounded-xl",
                doctor.healthy ? "bg-success/10" : "bg-destructive/10",
              )}
            >
              {doctor.healthy ? (
                <CircleCheck className="size-6 text-success" aria-hidden />
              ) : (
                <ShieldAlert className="size-6 text-destructive" aria-hidden />
              )}
            </span>
            <div className="min-w-0 flex-1">
              <p className="font-medium">
                {!doctor.healthy
                  ? t("unhealthy", { count: doctor.failed })
                  : doctor.warnings > 0
                    ? t("healthyWithWarnings", { count: doctor.warnings })
                    : t("healthy")}
              </p>
              <p className="text-sm text-muted-foreground">
                {t("counts", {
                  passed: doctor.passed,
                  warnings: doctor.warnings,
                  failed: doctor.failed,
                })}
              </p>
              {/* Say plainly what the two states mean — a failure blocks the
                  panel, a warning doesn't. */}
              {!doctor.healthy ? (
                <p className="mt-1 text-sm text-muted-foreground">{t("blockingNote")}</p>
              ) : null}
            </div>
          </div>

          <div className="space-y-3">
            {attention.map((check) => (
              <CheckRow key={check.key} check={check} labels={statusLabels} />
            ))}
            {/* Passing checks sink under a quiet divider so failures and
                warnings own the top of the list. */}
            {passed.length && attention.length ? (
              <div className="flex items-center gap-3 pt-2">
                <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                  {t("passedGroup", { count: passed.length })}
                </span>
                <span className="h-px flex-1 bg-border" />
              </div>
            ) : null}
            {passed.map((check) => (
              <CheckRow key={check.key} check={check} labels={statusLabels} />
            ))}
          </div>
        </>
      )}
    </div>
  );
}
