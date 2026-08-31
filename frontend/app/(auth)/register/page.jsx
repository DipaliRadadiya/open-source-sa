import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";

export const dynamic = "force-dynamic";

import Link from "next/link";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import { getBasicInfo } from "@/lib/basic-info/get-basic-info";
import { RegisterForm } from "@/components/forms/register-form";
import { Logo } from "@/components/logo";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";

export default async function RegisterPage() {
  const [user, basicInfo, t] = await Promise.all([
    getCurrentUser(),
    getBasicInfo(),
    getTranslations("auth"),
  ]);

  if (user) redirect("/dashboard");
  // Registration is bootstrap-only: once the admin exists the backend closes it.
  if (!basicInfo.registration_open) redirect("/login");

  return (
    <Card className="w-full gap-0 shadow-xl shadow-black/5">
      <CardHeader className="flex flex-col items-center gap-2 pb-6 text-center">
        <Logo className="mb-2 h-9 w-auto" />
        <CardTitle className="text-xl">{t("registerTitle")}</CardTitle>
        <CardDescription>{t("registerSubtitle")}</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-6">
        <RegisterForm policy={basicInfo.password_policy} />
        <p className="text-center text-sm text-muted-foreground">
          {t("haveAccount")}{" "}
          <Link href="/login" className="font-medium text-primary hover:underline">
            {t("loginLink")}
          </Link>
        </p>
      </CardContent>
    </Card>
  );
}
