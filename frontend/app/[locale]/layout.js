import { Geist, Geist_Mono } from "next/font/google";
import { NextIntlClientProvider } from "next-intl";
import { getMessages } from "next-intl/server";
import { ThemeProvider } from "@/components/theme-provider";
import { BrandingProvider } from "@/components/branding-provider";
import { Toaster } from "@/components/ui/sonner";
import { routing } from "@/i18n/routing";
import { getBranding } from "@/lib/branding/get-branding";
import { generatePalette } from "@/lib/theme/generate-palette";
import { buildThemeStyles } from "@/lib/theme/apply-theme";
import "../globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export function generateStaticParams() {
  return routing.locales.map((locale) => ({ locale }));
}

export async function generateMetadata() {
  const branding = await getBranding();

  return {
    title: branding.name,
    description: `${branding.name} panel`,
    icons: {
      icon: branding.favicon,
    },
  };
}

export default async function RootLayout({ children, params }) {
  const { locale } = await params;
  const messages = await getMessages();
  const branding = await getBranding();
  const palette = generatePalette(branding.primary_color);
  const themeStyles = buildThemeStyles(palette);

  return (
    <html lang={locale} suppressHydrationWarning className={`${geistSans.variable} ${geistMono.variable}`}>
      <head>
        {themeStyles && <style dangerouslySetInnerHTML={{ __html: themeStyles }} />}
      </head>
      <body>
        <NextIntlClientProvider messages={messages}>
          <ThemeProvider attribute="class" defaultTheme="system" enableSystem>
            <BrandingProvider branding={branding}>
              {children}
              <Toaster />
            </BrandingProvider>
          </ThemeProvider>
        </NextIntlClientProvider>
      </body>
    </html>
  );
}
