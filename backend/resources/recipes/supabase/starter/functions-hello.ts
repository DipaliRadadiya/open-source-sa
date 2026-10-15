// Starter Edge Function (panel recipe, RC-41). Invoke it with:
//
//   curl https://<your-domain>/functions/v1/hello --header 'apikey: <anon key>'
//
// Upstream's hello function accepts only the new opaque keys (sb_publishable_…,
// sb_secret_…), which this install does not configure. This one accepts the
// legacy anon or service_role key instead: it checks the key's HS256 signature
// against JWT_SECRET itself, with Web Crypto, so it needs no import from the
// network.
//
// Add your own functions as folders next to this one (<name>/index.ts); each is
// reachable at /functions/v1/<name>. Restart the functions container after
// adding one.

const encoder = new TextEncoder()

function base64url(bytes: Uint8Array): string {
  let binary = ''
  for (const byte of bytes) binary += String.fromCharCode(byte)
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

async function apiKeyRole(apiKey: string | null): Promise<string | null> {
  const secret = Deno.env.get('JWT_SECRET')
  const parts = (apiKey ?? '').split('.')
  if (!secret || parts.length !== 3) return null

  const key = await crypto.subtle.importKey(
    'raw',
    encoder.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  )
  const signature = await crypto.subtle.sign('HMAC', key, encoder.encode(`${parts[0]}.${parts[1]}`))
  if (base64url(new Uint8Array(signature)) !== parts[2]) return null

  try {
    const payload = JSON.parse(atob(parts[1].replace(/-/g, '+').replace(/_/g, '/')))
    return payload.role === 'anon' || payload.role === 'service_role' ? payload.role : null
  } catch {
    return null
  }
}

console.log('Hello from Functions!')

Deno.serve(async (req: Request) => {
  const role = await apiKeyRole(req.headers.get('apikey'))
  if (role === null) {
    return Response.json({ message: 'Invalid or missing apikey header' }, { status: 401 })
  }

  return Response.json({ message: 'Hello from Edge Functions!', role })
})
