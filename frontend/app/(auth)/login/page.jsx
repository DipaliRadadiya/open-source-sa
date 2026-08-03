import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";

export const dynamic = "force-dynamic";

import Link from "next/link";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import { getBasicInfo } from "@/lib/basic-info/get-basic-info";
import { LoginForm } from "@/components/forms/login-form";
import { Logo } from "@/components/logo";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";

export default async function LoginPage() {
  const [user, basicInfo, t] = await Promise.all([
    getCurrentUser(),
    getBasicInfo(),
    getTranslations("auth"),
  ]);

  if (user) redirect("/dashboard");

  return (
    <Card className="w-full gap-0 shadow-xl shadow-black/5">
      <CardHeader className="flex flex-col items-center gap-2 pb-6 text-center">
        <Logo className="mb-2 h-9 w-auto" />
        <CardTitle className="text-xl">{t("loginTitle")}</CardTitle>
        <CardDescription>{t("loginSubtitle")}</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-6">
        <LoginForm />
        {basicInfo.registration_open && (
          <p className="text-center text-sm text-muted-foreground">
            {t("noAccount")}{" "}
            <Link href="/register" className="font-medium text-primary hover:underline">
              {t("registerLink")}
            </Link>
          </p>
        )}
      </CardContent>
    </Card>
  );
}
