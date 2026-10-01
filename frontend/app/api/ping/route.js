// Liveness for this Next.js server (a separate unit from the API), polled by
// the restart curtain before reloading. A route handler so polling does not
// render a page each time.
export const dynamic = "force-dynamic";

export function GET() {
  return new Response(null, { status: 204 });
}
