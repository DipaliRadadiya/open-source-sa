#!/bin/bash
# End-to-end automation for the Docker stack, run on the panel's own box.
#
# Everything here goes through the HTTP API as a dedicated harness user, then
# checks the claim against Docker, the kernel or curl — the panel answering 200 is
# not the same as the thing working, which is how every bug this stack has shipped
# was found.
#
# One site at a time, deleted before the next: twelve container sites once took a
# 5.9GB box offline.
#
# Usage:  sudo -E HOST=$HOST USER_ID=2 bash tests/e2e/docker-stack.sh
#
# HOST     the panel's nip.io (or real) host suffix; the API is assumed at api.<HOST>
# USER_ID  the panel user to mint a token as. Use a DEDICATED account, never the first
#          admin — the activity log cannot otherwise tell the harness's actions from a
#          person's, which has already made one deletion unattributable.
HOST="${HOST:?set HOST, e.g. $HOST}"
USER_ID="${USER_ID:-2}"
API="https://api.$HOST/api"
PANEL="https://panel.$HOST"
PASS=0; FAIL=0
TAG="e2e$(shuf -i 100-999 -n 1)"
echo "run tag: $TAG"
ok()   { echo "PASS  $1"; PASS=$((PASS+1)); }
bad()  { echo "FAIL  $1 -- $2"; FAIL=$((FAIL+1)); }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "expected [$3] got [$2]"; fi; }
api()  { local m=$1 p=$2 b=${3:-}
         if [ -n "$b" ]; then curl -sk -X "$m" "$API$p" -H "Authorization: Bearer $T" -H "Content-Type: application/json" -d "$b"
         else curl -sk -X "$m" "$API$p" -H "Authorization: Bearer $T"; fi; }
code() { local m=$1 p=$2 b=${3:-}
         if [ -n "$b" ]; then curl -sk -o /dev/null -w "%{http_code}" -X "$m" "$API$p" -H "Authorization: Bearer $T" -H "Content-Type: application/json" -d "$b"
         else curl -sk -o /dev/null -w "%{http_code}" -X "$m" "$API$p" -H "Authorization: Bearer $T"; fi; }
# Nested quoting matters here: the selector arrives containing single quotes, so the
# eval string has to be double-quoted inside the double-quoted -c argument. Written
# the other way round it is a Python syntax error that `2>/dev/null` hides, and every
# id comes back empty — which reads exactly like the API returning nothing.
jq1()  { python3 -c "import json,sys;d=json.load(sys.stdin);print(eval(\"d$1\"))"; }
waitstatus() { local id=$1 want=$2 n=${3:-40}
  for i in $(seq 1 $n); do
    s=$(api GET /applications/$id | jq1 "['application']['status']")
    [ "$s" = "$want" ] && return 0
    [ "$s" = "failed" ] && return 1
    sleep 6
  done; return 1; }

sweep() {
  [ -z "${T:-}" ] && return
  echo "--- sweeping $TAG"
  for a in $(api GET /applications | python3 -c "import json,sys;print(' '.join(str(x['id']) for x in json.load(sys.stdin).get('applications',[]) if '$TAG' in x['name']))" 2>/dev/null); do
    code DELETE /applications/$a '{"remove_files":true,"remove_docker_resources":true}' >/dev/null; done
  for d in $(api GET /docker/databases | python3 -c "import json,sys;print(' '.join(str(x['id']) for x in json.load(sys.stdin).get('databases',[]) if '$TAG' in x['name']))" 2>/dev/null); do
    code DELETE /docker/databases/$d '{"remove_data":true}' >/dev/null; done
  for u in $(api GET /system-users | python3 -c "import json,sys;print(' '.join(str(x['id']) for x in json.load(sys.stdin).get('system_users',[]) if x['username'].startswith('$TAG')))" 2>/dev/null); do
    code DELETE /system-users/$u >/dev/null; done
  code DELETE /docker/networks/$TAG-net >/dev/null
  code DELETE /docker/volumes/$TAG-vol >/dev/null
  # Only this run's token. See the note at the mint.
  [ -n "${TOKEN_ID:-}" ] && cd /var/www/panel/backend && sudo -u panel php artisan tinker \
    --execute="App\Models\User::find($USER_ID)->tokens()->whereKey($TOKEN_ID)->delete();" >/dev/null 2>&1
}
trap sweep EXIT

cd /var/www/panel/backend
# Mint, and remember WHICH token this run owns.
#
# The sweep used to delete every token the harness user had, which meant one run's
# cleanup revoked a concurrent run's credential mid-flight — the symptom was a clean
# first half and `{"message":"Unauthenticated."}` from the create onwards, which reads
# like an auth bug in the panel.
# Double-quoted so `$USER_ID` expands, with PHP's own `$` escaped. Single-quoted it
# left `$USER_ID` literal, PHP saw an undefined variable, `find(null)` returned null
# and `null->createToken()` was fatal — so `T` held the first word of an error message
# and every call came back 401.
read -r T TOKEN_ID < <(sudo -u panel php artisan tinker --execute="\$t = App\Models\User::find($USER_ID)->createToken('e2e-run'); echo \$t->plainTextToken, ' ', \$t->accessToken->id;" | tail -1)

# **Checked by using it, not by measuring its length.** `[ -n "$T" ]` passed on that
# error message, so the run reported a minted token and then failed 40 assertions for
# a reason none of them named.
probe=$(code GET /docker/limits)
if [ "$probe" = "200" ]; then ok "harness token works (id $TOKEN_ID)"; else
  bad "harness token" "GET /docker/limits answered $probe"; exit 1; fi

echo "=== 1. capabilities and stack gating"
perms=$(api GET /permissions)
echo "$perms" | grep -q '"name":"docker"' && ok "docker permission visible" || bad "docker permission" "absent"
echo "$perms" | grep -q '"name":"php"' && bad "php hidden on docker box" "still visible" || ok "php hidden on docker box"
echo "$perms" | grep -q '"name":"node"' && bad "node hidden on docker box" "still visible" || ok "node hidden on docker box"
echo "$perms" | grep -q '"name":"database"' && bad "database hidden on docker box" "still visible" || ok "database hidden on docker box"
check "host database API refuses (409)" "$(code GET /databases)" "409"
check "node API refuses (409)" "$(code GET /node)" "409"
check "docker limits endpoint" "$(code GET /docker/limits)" "200"
cores=$(api GET /docker/limits | jq1 "['limits']['cpus']")
[ "$cores" -ge 1 ] 2>/dev/null && ok "host cpu count reported ($cores)" || bad "host cpu count" "$cores"

echo "=== 2. doctor"
doc=$(sudo -u panel php artisan panel:doctor 2>&1)
echo "$doc" | grep -q "Docker is not installed" && bad "doctor: docker installed" "reports missing" || ok "doctor: docker installed"
echo "$doc" | grep -qi "refused the panel" && bad "doctor: daemon reachable" "denied" || ok "doctor: daemon reachable"

echo "=== 3. networks"
check "create network" "$(code POST /docker/networks "{\"name\":\"$TAG-net\"}")" "201"
api GET /docker/networks | grep -q "\"name\":\"$TAG-net\"" && ok "network listed" || bad "network listed" "absent"
check "refuse duplicate network" "$(code POST /docker/networks "{\"name\":\"$TAG-net\"}")" "422"
check "refuse deleting bridge" "$(code DELETE /docker/networks/bridge)" "422"

echo "=== 4. volumes"
check "create volume" "$(code POST /docker/volumes "{\"name\":\"$TAG-vol\"}")" "201"
api GET /docker/volumes | grep -q "\"name\":\"$TAG-vol\"" && ok "volume listed" || bad "volume listed" "absent"

echo "=== 5. simple container site, with limits"
uid=$(api POST /system-users '{"username":"'"$TAG"'","password":"Xk8vQ2mPl9wZ4tRn","shell":"/bin/bash"}' | jq1 "['system_user']['id']")
[ -n "$uid" ] && ok "system user created ($uid)" || bad "system user" "none"
# Every tagged value through printf arguments rather than inside the single-quoted
# format: `$TAG` in a single-quoted string is literal, and a literal `$TAG-net` fails
# `ExistingDockerNetwork` as a 422 that reads like the rule refusing a real network.
# HOST through an argument as well. Left inside the single-quoted format it stayed
# literal and the API refused `e2e495.$HOST` as a malformed domain — the third time in
# this script that a variable inside single quotes read as a validation bug.
body=$(printf '{"name":"%ssite","domain":"%s.%s","system_user_id":%s,"site_type":"docker","image":"nginx:1.27-alpine","container_port":80,"web_root":"public_html","php_version":"8.4","memory_limit":"192m","cpu_limit":"0.5","docker_network":"%s-net"}' "$TAG" "$TAG" "$HOST" "$uid" "$TAG")
raw=$(api POST /applications "$body")
sid=$(echo "$raw" | jq1 "['application']['id']")
# The response when there is no id. Three separate harness bugs read as "the API
# returned nothing" because this was not printed.
[ -n "$sid" ] && ok "site created ($sid)" || bad "site created" "$(echo "$raw" | head -c 200)"
if waitstatus "$sid" active; then ok "site provisioned active"; else bad "site provisioned" "did not reach active"; fi

cname=$(sudo docker ps --filter "name=sv-app-$sid" --format '{{.Names}}' | head -1)
[ -n "$cname" ] && ok "container running ($cname)" || bad "container running" "none"
mem=$(sudo docker inspect "$cname" --format '{{.HostConfig.Memory}}' 2>/dev/null)
cpu=$(sudo docker inspect "$cname" --format '{{.HostConfig.NanoCpus}}' 2>/dev/null)
check "memory limit at kernel" "$mem" "201326592"
check "cpu quota at kernel" "$cpu" "500000000"
sudo docker exec "$cname" cat /sys/fs/cgroup/cpu.max 2>/dev/null | grep -q "^50000 100000$" && ok "cgroup cpu.max is 0.5 core" || bad "cgroup cpu.max" "$(sudo docker exec "$cname" cat /sys/fs/cgroup/cpu.max 2>/dev/null)"
ports=$(sudo docker port "$cname" 2>/dev/null | tr '\n' ' ')
echo "$ports" | grep -q "0.0.0.0" && bad "publishes to loopback only" "$ports" || ok "publishes to loopback only"
check "site serves over http" "$(curl -s -o /dev/null -w '%{http_code}' --resolve $TAG.$HOST:80:127.0.0.1 http://$TAG.$HOST/)" "200"
# Auto-issue runs after the site is active, so https is 000 until the cert lands.
for i in $(seq 1 20); do
  hc=$(curl -sk -o /dev/null -w '%{http_code}' --resolve $TAG.$HOST:443:127.0.0.1 https://$TAG.$HOST/)
  [ "$hc" = "200" ] && break; sleep 6
done
check "site serves over https once the certificate is issued" "$hc" "200"
sudo docker inspect "$cname" --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' | grep -q "$TAG-net" && ok "joined the chosen network" || bad "joined network" "absent"
alias_ok=$(sudo docker run --rm --network $TAG-net alpine sh -c "getent hosts ${TAG}site >/dev/null && echo yes || echo no" 2>/dev/null | tail -1)
check "reachable by slug alias on the network" "$alias_ok" "yes"

echo "=== 6. limit validation refusals"
check "refuse cpu above host cores" "$(code PUT /applications/$sid/container "{\"cpu_limit\":\"$((cores+8))\"}")" "422"
check "refuse zero cpu" "$(code PUT /applications/$sid/container '{"cpu_limit":"0"}')" "422"
check "refuse memory with no unit" "$(code PUT /applications/$sid/container '{"memory_limit":"512"}')" "422"
check "refuse memory below docker floor" "$(code PUT /applications/$sid/container '{"memory_limit":"2m"}')" "422"
check "clear the cpu limit" "$(code PUT /applications/$sid/container '{"cpu_limit":null}')" "200"
sleep 8
cname=$(sudo docker ps --filter "name=sv-app-$sid" --format '{{.Names}}' | head -1)
check "cpu quota removed at kernel" "$(sudo docker inspect "$cname" --format '{{.HostConfig.NanoCpus}}')" "0"

echo "=== 7. volume mount and size"
check "mount a volume" "$(code PUT /applications/$sid/container "{\"volume_mounts\":[{\"volume\":\"$TAG-vol\",\"path\":\"/data\"}]}")" "200"
sleep 8
cname=$(sudo docker ps --filter "name=sv-app-$sid" --format '{{.Names}}' | head -1)
sudo docker inspect "$cname" --format '{{range .Mounts}}{{.Name}} {{end}}' | grep -q "$TAG-vol" && ok "volume mounted into container" || bad "volume mounted" "absent"
check "refuse a mount over the site files" "$(code PUT /applications/$sid/container "{\"volume_mounts\":[{\"volume\":\"$TAG-vol\",\"path\":\"/app\"}]}")" "422"
sudo docker exec "$cname" sh -c 'echo e2e > /data/marker' 2>/dev/null && ok "volume is writable" || bad "volume writable" "no"
sudo -u panel php artisan tinker --execute="\$a=App\Models\Application::find($sid); app(App\Services\Server\Applications\FileBrowser::class)->applicationSize(\$a, refresh:true); \$a->refresh(); echo \$a->directory_size_bytes, ' ', \$a->volume_size_bytes;" > /tmp/size.txt 2>&1
read total vols < <(tail -1 /tmp/size.txt)
[ "${vols:-0}" -ge 0 ] 2>/dev/null && ok "size measured with volume share (total=$total volumes=$vols)" || bad "size measured" "$(cat /tmp/size.txt | tail -1)"
[ "${total:-0}" -ge "${vols:-0}" ] 2>/dev/null && ok "total includes the volume share" || bad "total vs volumes" "$total < $vols"

echo "=== 8. compose editor"
gen=$(api GET /applications/$sid/container/compose)
echo "$gen" | grep -q "mem_limit" && ok "generated compose readable" || bad "generated compose" "no mem_limit"
echo "$gen" | grep -q "127.0.0.1" && ok "generated compose publishes to loopback" || bad "generated compose loopback" "absent"
bad_yaml='{"compose":"services:\n  web:\n    image: nginx:1.27-alpine\n    ports:\n      - \"0.0.0.0:31000:80\"\n"}'
check "refuse compose publishing to every address" "$(code PUT /applications/$sid/container/compose "$bad_yaml")" "422"
check "site still serves after a refused save" "$(curl -sk -o /dev/null -w '%{http_code}' --resolve $TAG.$HOST:443:127.0.0.1 https://$TAG.$HOST/)" "200"

echo "=== 9. delete and cleanup"
check "delete the site" "$(code DELETE /applications/$sid '{"remove_files":true,"remove_docker_resources":true}')" "200"
sleep 10
[ -z "$(sudo docker ps -a --filter "name=sv-app-$sid" --format '{{.Names}}')" ] && ok "containers gone" || bad "containers gone" "still present"
[ ! -d /home/$TAG/${TAG}site ] && ok "site directory gone" || bad "site directory" "still present"
[ -z "$(sudo ls /etc/nginx/sites-enabled/ 2>/dev/null | grep -i ${TAG}site)" ] && ok "vhost gone" || bad "vhost gone" "still present"
code DELETE /system-users/$uid >/dev/null
# Not deleted here: the site delete asked for `remove_docker_resources`, so the panel
# has already taken them. Asserting the absence is the contract; deleting again just
# tests that a second delete 404s.
[ -z "$(sudo docker network ls --format '{{.Name}}' | grep -x "$TAG-net")" ] && ok "site delete removed its network" || bad "network removed by site delete" "still present"
[ -z "$(sudo docker volume ls --format '{{.Name}}' | grep -x "$TAG-vol")" ] && ok "site delete removed its volume" || bad "volume removed by site delete" "still present"

echo "=== 10. containerised databases over the API (no UI)"
dbid=$(api POST /docker/databases "{\"name\":\"${TAG}db\",\"engine\":\"postgres\",\"version\":\"18\"}" | jq1 "['database']['id']")
[ -n "$dbid" ] && ok "database created ($dbid)" || bad "database created" "none"
if [ -n "$dbid" ]; then
  run=$(api GET /docker/databases | python3 -c "import json,sys;print([d['running'] for d in json.load(sys.stdin)['databases'] if d['id']==$dbid][0])")
  check "database reports running" "$run" "True"
  pw=$(api GET /docker/databases/$dbid/credentials | jq1 "['credentials']['password']")
  [ ${#pw} -ge 16 ] && ok "credentials revealed (${#pw} chars)" || bad "credentials" "len ${#pw}"
  sudo docker exec sv-db-$dbid-db-1 psql "postgresql://${TAG}db:$pw@127.0.0.1:5432/${TAG}db" -c "SELECT 1" >/dev/null 2>&1 && ok "database accepts the revealed password" || bad "database connect" "refused"
  mp=$(sudo docker inspect sv-db-$dbid-db-1 --format '{{range .Mounts}}{{.Destination}} {{end}}')
  echo "$mp" | grep -q "/var/lib/postgresql" && ok "postgres 18 mounted at the path it needs" || bad "postgres 18 mount" "$mp"
  # a second one must not take the same port
  dbid2=$(api POST /docker/databases "{\"name\":\"${TAG}db2\",\"engine\":\"redis\",\"version\":\"8\"}" | jq1 "['database']['id']")
  p1=$(api GET /docker/databases | python3 -c "import json,sys;d=json.load(sys.stdin)['databases'];print([x['host_port'] for x in d if x['id']==$dbid][0])")
  p2=$(api GET /docker/databases | python3 -c "import json,sys;d=json.load(sys.stdin)['databases'];print([x['host_port'] for x in d if x['id']==$dbid2][0])" 2>/dev/null)
  [ -n "$p2" ] && [ "$p1" != "$p2" ] && ok "two databases get different ports ($p1, $p2)" || bad "distinct ports" "$p1 vs $p2"
  code DELETE /docker/databases/$dbid2 '{"remove_data":true}' >/dev/null
  check "delete the database" "$(code DELETE /docker/databases/$dbid '{"remove_data":true}')" "200"
fi

echo "=== 11. activity log survived everything"
check "scoped activity log" "$(code GET '/activity-log?scope=server')" "200"
api GET '/activity-log?scope=server' | grep -q '"description":"activity\.' && bad "no untranslated activity rows" "found a raw key" || ok "no untranslated activity rows"

echo
echo "RESULT pass=$PASS fail=$FAIL"
