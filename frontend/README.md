# ServerAvatar OSS — Frontend

Next.js 16 (App Router, JS only) frontend for the ServerAvatar OSS server-management panel. Consumes the Laravel backend API, no business logic lives here.

## Stack

Next.js 16 · Tailwind CSS v4 · shadcn/ui (Radix) · next-themes · next-intl · Zustand (where needed) · TanStack Table · react-hook-form + Zod · Axios · Sonner · Recharts · Lucide React.

## Getting started

```bash
npm install
cp .env.example .env.local   # set NEXT_PUBLIC_API_URL
npm run dev
```

Open [http://localhost:3000](http://localhost:3000).

## Scripts

- `npm run dev` — start the dev server
- `npm run build` — production build
- `npm run start` — start the production server
- `npm run lint` — run ESLint

## Structure

```
app/[locale]/
  (auth)/    unauthenticated pages
  (app)/     authenticated dashboard pages
  (admin)/   admin-only pages (role-gated)
components/  ui/ (shadcn primitives) + sections/ + forms/
lib/         api/, auth/, schemas/, theme/
i18n/        next-intl routing config
messages/    locale dictionaries
```
