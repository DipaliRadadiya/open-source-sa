import { Geist, Geist_Mono } from "next/font/google";
import { getLocale, getMessages } from "next-intl/server";
import { IntlProvider } from "@/components/intl-provider";
import { ThemeProvider } from "@/components/theme-provider";
import { BrandingProvider } from "@/components/branding-provider";
import { Toaster } from "@/components/ui/sonner";
import { getBranding } from "@/lib/branding/get-branding";
import { generatePalette } from "@/lib/theme/generate-palette";
import { buildThemeStyles } from "@/lib/theme/apply-theme";
import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export async function generateMetadata() {
  const branding = await getBranding();

  return {
    // Pages set a plain title; the template appends the brand.
    title: { default: branding.name, template: `%s · ${branding.name}` },
    description: `${branding.name} panel`,
    icons: {
      icon: branding.favicon,
    },
  };
}

export default async function RootLayout({ children }) {
  const locale = await getLocale();
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
        <IntlProvider locale={locale} messages={messages}>
          <ThemeProvider attribute="class" defaultTheme="system" enableSystem>
            <BrandingProvider branding={branding}>
              {children}
              <Toaster />
            </BrandingProvider>
          </ThemeProvider>
        </IntlProvider>
      </body>
    </html>
  );
}
