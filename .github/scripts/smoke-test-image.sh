#!/usr/bin/env bash
#
# Smoke-tests a TaskR image and fails unless it comes up healthy. Runs on pull
# requests (docker.yml) and before a release is pushed (release.yml): v0.4.0
# shipped an image that could not start, because images were only ever pushed.
#
#   .github/scripts/smoke-test-image.sh <image>
#
# Two passes. Octane alone comes first, entrypoint bypassed (no database
# needed), so that an image which cannot start reports its own error:
#   1. The Caddyfile and its Mercure hub, clean startup logs, the Mercure
#      preflight, and what the image contains.
#   2. The real stack, from docker-compose.yml: the entrypoint, migrations at
#      boot, both healthchecks, persistence across a down/up, and an update
#      published by the worker reaching a subscriber through the hub.
#
# Needs docker (compose 2.24+), curl and openssl. SMOKE_PLATFORM=linux/amd64
# tests an architecture other than the host's (emulated).

set -euo pipefail

image="${1:?usage: smoke-test-image.sh <image>}"
here="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$here/../.." && pwd)"
name="taskr-smoke-$$"
stack=""
platform="${SMOKE_PLATFORM:-}"

fail() {
    echo "::error::$*" >&2
    exit 1
}

step() {
    echo "::group::$*"
}

# `${arr[@]+…}`: an empty array is not an unbound variable under `set -u`
# on bash 3.2 (macOS).
platform_args=()
if [ -n "$platform" ]; then
    platform_args=(--platform "$platform")
    export DOCKER_DEFAULT_PLATFORM="$platform"
fi

run() {
    docker run ${platform_args[@]+"${platform_args[@]}"} "$@"
}

compose() {
    docker compose --project-name "$name" --project-directory "$stack" --env-file "$stack/.env" \
        --file "$stack/docker-compose.yml" --file "$here/smoke-test.compose.yml" "$@"
}

cleanup() {
    docker rm --force "$name-mercure" "$name-plain" >/dev/null 2>&1 || true
    if [ -n "$stack" ]; then
        compose down --volumes --remove-orphans >/dev/null 2>&1 || true
        # The entrypoint chowns the storage bind mount to www-data: only a
        # container (root) can remove what it created on a Linux host.
        run --rm --volume "$stack:/stack" --entrypoint sh "$image" -c 'rm -rf /stack/.[!.]* /stack/*' >/dev/null 2>&1 || true
        rm -rf "$stack" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

http_code() {
    curl --silent --output /dev/null --write-out '%{http_code}' --max-time 15 "$1"
}

expect_status() { # <url> <expected code> <label>
    local code
    code="$(http_code "$1")"
    [ "$code" = "$2" ] || fail "$3: expected HTTP $2, got $code ($1)"
}

expect_message() { # <output> <text>
    grep --quiet --fixed-strings "$2" <<<"$1" || fail "the message does not mention '$2': $1"
}

wait_for_healthy() { # <container> <seconds>
    local started=$SECONDS deadline=$((SECONDS + $2)) status="?"
    while [ "$SECONDS" -lt "$deadline" ]; do
        status="$(docker inspect --format '{{.State.Status}}/{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$1")"
        case "$status" in
            running/healthy) return 0 ;;
            exited/* | dead/*) break ;;
        esac
        sleep 2
    done
    echo "--- $1 is '$status' after $((SECONDS - started))s; logs:" >&2
    docker logs "$1" 2>&1 | tail -n 60 >&2
    return 1
}

address_of() { # <container> → where the host can reach its published 8080
    local published
    published="$(docker port "$1" 8080/tcp | head -n 1)"
    echo "${published/0.0.0.0/127.0.0.1}"
}

# Octane alone: the entrypoint is bypassed on purpose, it waits for a
# database. The healthcheck is the compose file's.
start_octane() { # <container> [--env KEY=VALUE …]
    local container="$1"
    shift
    run --detach --name "$container" \
        --publish '127.0.0.1::8080' \
        --health-cmd='php artisan octane:status >/dev/null 2>&1 || exit 1' \
        --health-interval=2s --health-timeout=10s --health-retries=30 --health-start-period=5s \
        --env APP_ENV=production --env "APP_KEY=$app_key" --env APP_URL=http://localhost:8080 \
        --env CACHE_STORE=array --env SESSION_DRIVER=array --env LOG_CHANNEL=stderr \
        "$@" \
        --entrypoint php "$image" artisan octane:frankenphp --host=0.0.0.0 --port=8080 >/dev/null
}

app_key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
secret="$(openssl rand -hex 32)"
mercure="$name-mercure"
plain="$name-plain"

echo "Smoke-testing $image${platform:+ ($platform)}"

step "1/8 Octane boots with the Mercure hub"
start_octane "$mercure" --env BROADCAST_CONNECTION=mercure --env "MERCURE_JWT_SECRET=$secret"
wait_for_healthy "$mercure" 120
base="http://$(address_of "$mercure")"
expect_status "$base/up" 200 '/up'
expect_status "$base/.well-known/mercure" 401 '/.well-known/mercure (the hub answers, anonymous subscriptions refused)'
docker exec "$mercure" test -f /app/storage/caddy/mercure.db \
    || fail "the hub's subscriptions database is not on the storage volume (/app/storage/caddy/mercure.db)"
echo "::endgroup::"
step "2/8 Startup logs carry no PHP warning"
logs="$(docker logs "$mercure" 2>&1 || true)"
echo "$logs"
if grep --extended-regexp "PHP (Warning|Notice|Deprecated)|Can't preload|Warning: " <<<"$logs"; then
    fail "PHP warnings at startup (see above)"
fi
echo "::endgroup::"
step "3/8 Octane boots without the hub (no BROADCAST_CONNECTION)"
start_octane "$plain"
wait_for_healthy "$plain" 120
plain_base="http://$(address_of "$plain")"
expect_status "$plain_base/up" 200 '/up'
expect_status "$plain_base/.well-known/mercure" 404 '/.well-known/mercure (no hub configured)'
echo "::endgroup::"
step "4/8 The Mercure preflight refuses an empty secret"
if output="$(run --rm --env BROADCAST_CONNECTION=mercure --env MERCURE_JWT_SECRET= "$image" 2>&1)"; then
    fail "the container started with BROADCAST_CONNECTION=mercure and an empty MERCURE_JWT_SECRET"
fi
expect_message "$output" 'MERCURE_JWT_SECRET'
expect_message "$output" 'openssl rand -hex 32'
echo "$output"
echo "::endgroup::"
step "5/8 The Mercure preflight refuses a malformed secret"
if output="$(run --rm --env BROADCAST_CONNECTION=mercure --env MERCURE_JWT_SECRET=too-short "$image" 2>&1)"; then
    fail "the container started with a MERCURE_JWT_SECRET too short to sign HS256 tokens"
fi
expect_message "$output" 'MERCURE_JWT_SECRET'
expect_message "$output" 'openssl rand -hex 32'
echo "$output"
echo "::endgroup::"
step "6/8 The image carries neither secrets nor development files"
run --rm --entrypoint sh "$image" -ec '
    cd /app
    for path in .env .env.production .env.local .DS_Store tests docs .github .storybook .mcp.json opencode.json \
        package-lock.json pnpm-lock.yaml pnpm-workspace.yaml tsconfig.json vite.config.js .editorconfig \
        Dockerfile .dockerignore bootstrap/cache/config.php storage/logs/laravel.log; do
        [ ! -e "$path" ] || { echo "unexpected in the image: /app/$path"; exit 1; }
    done
    for path in artisan composer.json VERSION public/build/manifest.json vendor/autoload.php; do
        [ -s "$path" ] || { echo "missing from the image: /app/$path"; exit 1; }
    done
'
versions="$(run --rm --entrypoint frankenphp "$image" version | head -n 1)"
echo "$versions"
# On the record of each release run: what the floating base tag resolved to.
[ -z "${GITHUB_STEP_SUMMARY:-}" ] || echo "\`$image\`: $versions" >> "$GITHUB_STEP_SUMMARY"
echo "::endgroup::"

step "7/8 The docker compose stack comes up healthy: migrations at boot, app and worker"
stack="$(mktemp -d "${TMPDIR:-/tmp}/taskr-smoke.XXXXXX")"
export TASKR_SMOKE_IMAGE="$image"

# The quick start, verbatim: cp .env.example .env, then the two values it
# leaves to fill in. Everything lives in a throwaway directory so the stack
# never touches a developer's own .env and storage/. The storage mount starts
# out empty on purpose: the entrypoint has to build Laravel's skeleton itself
# (a fresh volume does not carry the git checkout's directories).
mkdir -p "$stack/docker" "$stack/storage"
cp "$repo/docker-compose.yml" "$stack/"
cp "$repo/docker/postgres-init.sql" "$stack/docker/"
{
    grep -v --regexp='^\(APP_KEY\|MERCURE_JWT_SECRET\)=' "$repo/.env.example"
    printf '\nAPP_KEY=%s\nMERCURE_JWT_SECRET=%s\n' "$app_key" "$secret"
} > "$stack/.env"

stack_up() {
    compose up --detach --wait --wait-timeout 240 2>&1 | tail -n 12
}

stack_diagnostics() {
    compose ps --all >&2 || true
    compose logs --no-log-prefix --tail 60 app worker postgres >&2 || true
}

if ! stack_up; then
    stack_diagnostics
    fail "the compose stack did not come up healthy (see above)"
fi

health_of() {
    docker inspect --format '{{.State.Health.Status}}' "$(compose ps --quiet "$1")"
}

for service in app worker; do
    [ "$(health_of "$service")" = healthy ] || { stack_diagnostics; fail "the $service role is not healthy"; }
done
compose ps

stack_base="http://$(compose port app 8080 | head -n 1)"
expect_status "$stack_base/up" 200 '/up'
expect_status "$stack_base/.well-known/mercure" 401 '/.well-known/mercure'

if ! migrations="$(compose exec --no-TTY app php artisan migrate:status --no-interaction 2>&1)"; then
    stack_diagnostics
    fail "php artisan migrate:status failed: $migrations"
fi
echo "$migrations" | tail -n 6
if grep --quiet 'Pending' <<<"$migrations"; then
    fail "migrations were still pending after boot"
fi

for service in app worker; do
    if compose logs --no-log-prefix "$service" | grep --extended-regexp 'ERROR|Exception'; then
        fail "errors in the $service logs (see above)"
    fi
done

# The hub's database lives on the storage bind mount: it has to outlive the
# containers, and the app has to boot again on the migrated database.
[ -f "$stack/storage/caddy/mercure.db" ] || fail "the hub's database is not on the storage bind mount"
compose down >/dev/null 2>&1 || true
[ -f "$stack/storage/caddy/mercure.db" ] || fail "the hub's database did not survive docker compose down"
if ! stack_up; then
    stack_diagnostics
    fail "the compose stack did not come back up on an existing database (see above)"
fi
stack_base="http://$(compose port app 8080 | head -n 1)"
expect_status "$stack_base/up" 200 '/up (after a down/up)'
echo "::endgroup::"

step "8/8 The worker publishes to the hub, and a subscriber receives it"
# A queue worker is not a FrankenPHP request: it publishes over HTTP, which
# needs the hub's address from the container network (.env.example documents
# the pair). Plain HTTP here, hence a cookie name without the "__Secure-"
# prefix the protocol defaults to.
cat >> "$stack/.env" <<'ENV'
MERCURE_URL=http://app:8080/.well-known/mercure
MERCURE_PUBLIC_URL=${APP_URL}/.well-known/mercure
MERCURE_COOKIE_NAME=mercure_access_token
ENV
if ! stack_up; then
    stack_diagnostics
    fail "the compose stack did not come back up with MERCURE_URL set (see above)"
fi
stack_base="http://$(compose port app 8080 | head -n 1)"
expect_status "$stack_base/.well-known/mercure" 401 '/.well-known/mercure (the hub stays embedded with MERCURE_URL set)'

# A subscriber authorized the way the broadcaster authorizes one: its token
# factory, a subscribe grant on the topic of a private channel.
topic='https://laravel.alt/echo/channel/private-chat-threads.smoke'
mint="$(printf 'echo "TOKEN_START", app(Illuminate\Contracts\Broadcasting\Factory::class)->connection()->getHub()->getFactory()->create([new Symfony\Component\Mercure\Jwt\Grant([Symfony\Component\Mercure\Jwt\Grant::ACTION_SUBSCRIBE], ["%s"])]), "TOKEN_END";' "$topic")"
if ! raw_token="$(compose exec --no-TTY app php artisan tinker --execute "$mint" 2>&1)"; then
    fail "could not mint a subscriber token: $raw_token"
fi
token="$(sed -n 's/.*TOKEN_START\(.*\)TOKEN_END.*/\1/p' <<<"$raw_token" | tr -d '[:space:]')"
[ -n "$token" ] || fail "no subscriber token minted: $raw_token"

subscriber="$stack/subscriber.out"
encoded="${topic//:/%3A}"
encoded="${encoded//\//%2F}"
: > "$subscriber"
(curl --no-buffer --silent --max-time 30 --header "Cookie: mercure_access_token=$token" \
    "$stack_base/.well-known/mercure?match=$encoded" > "$subscriber" 2>&1 &)

# The stream opens with a keep-alive comment: publishing before the
# subscription is established would miss the update (no replay without a
# Last-Event-ID).
deadline=$((SECONDS + 15))
until [ -s "$subscriber" ]; do
    if [ "$SECONDS" -ge "$deadline" ]; then
        cat "$subscriber" >&2
        fail "the subscriber could not connect to the hub"
    fi
    sleep 1
done

if ! compose exec --no-TTY worker php artisan tinker --execute \
    'app(Illuminate\Contracts\Broadcasting\Factory::class)->connection()->broadcast([new Illuminate\Broadcasting\PrivateChannel("chat-threads.smoke")], "smoke.event", ["hello" => "from the worker"]);' > "$stack/publish.out" 2>&1; then
    cat "$stack/publish.out" >&2
    fail "the worker could not publish to the hub"
fi

deadline=$((SECONDS + 15))
until grep --quiet '\"event\":\"smoke.event\"' "$subscriber"; do
    if [ "$SECONDS" -ge "$deadline" ]; then
        echo "subscriber output:" >&2
        cat "$subscriber" >&2
        stack_diagnostics
        fail "the subscriber never received the update the worker published"
    fi
    sleep 1
done
grep --fixed-strings 'data: ' "$subscriber" | head -n 1
echo "::endgroup::"

echo "Smoke test passed: $image"
