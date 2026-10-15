# Supabase legacy API keys, derived from JWT_SECRET (panel recipe, RC-41).
#
# Sourced (". /opt/supabase-keys/jwt-keys.sh") by the start command of every
# service that needs the anon or service_role key. Each key is an HS256 JWT
# over a FIXED header and payload, so the same JWT_SECRET always gives the same
# two keys in every container and after every restart: nothing is stored and
# nothing has to be shared between containers.
#
# The payload matches upstream's utils/generate-keys.sh (role, iss "supabase",
# iat, exp), with fixed times: issued 2025-10-14, expiring 2035-10-12.
# Changing JWT_SECRET changes both keys and signs every user out.
#
# openssl is used where the image has it (kong, studio, realtime), node where
# it does not (storage). Both produce the same bytes.

sv_supabase_jwt() {
    sv_payload="{\"role\":\"$1\",\"iss\":\"supabase\",\"iat\":1760400000,\"exp\":2075760000}"

    if command -v openssl >/dev/null 2>&1; then
        sv_h=$(printf '%s' '{"alg":"HS256","typ":"JWT"}' | openssl base64 -A | tr '+/' '-_' | tr -d '=')
        sv_p=$(printf '%s' "$sv_payload" | openssl base64 -A | tr '+/' '-_' | tr -d '=')
        sv_s=$(printf '%s' "$sv_h.$sv_p" | openssl dgst -sha256 -hmac "$JWT_SECRET" -binary | openssl base64 -A | tr '+/' '-_' | tr -d '=')
        printf '%s' "$sv_h.$sv_p.$sv_s"
    elif command -v node >/dev/null 2>&1; then
        node -e '
const c = require("crypto");
const b = (s) => Buffer.from(s).toString("base64url");
const h = b("{\"alg\":\"HS256\",\"typ\":\"JWT\"}");
const p = b(process.argv[1]);
process.stdout.write(h + "." + p + "." + c.createHmac("sha256", process.env.JWT_SECRET).update(h + "." + p).digest("base64url"));
' "$sv_payload"
    fi
}

if [ -z "$JWT_SECRET" ]; then
    echo "jwt-keys.sh: JWT_SECRET is not set" >&2
    exit 1
fi

ANON_KEY=$(sv_supabase_jwt anon)
SERVICE_ROLE_KEY=$(sv_supabase_jwt service_role)

if [ -z "$ANON_KEY" ] || [ -z "$SERVICE_ROLE_KEY" ]; then
    echo "jwt-keys.sh: cannot derive the API keys (this image has neither openssl nor node)" >&2
    exit 1
fi

# Every name the upstream services read the two keys under.
SUPABASE_ANON_KEY=$ANON_KEY
SUPABASE_SERVICE_KEY=$SERVICE_ROLE_KEY
SUPABASE_SERVICE_ROLE_KEY=$SERVICE_ROLE_KEY
SERVICE_KEY=$SERVICE_ROLE_KEY
export ANON_KEY SERVICE_ROLE_KEY SUPABASE_ANON_KEY SUPABASE_SERVICE_KEY SUPABASE_SERVICE_ROLE_KEY SERVICE_KEY
unset sv_payload sv_h sv_p sv_s
