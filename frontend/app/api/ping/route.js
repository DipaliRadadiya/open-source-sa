// Liveness for this Next.js server, polled by the restart curtain before reloading.
export const dynamic = "force-dynamic";

export function GET() {
  return new Response(null, { status: 204 });
}
