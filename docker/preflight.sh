#!/bin/sh
# Preflight checks, run by the entrypoint in every role: unusable
# configurations stop the container here, with a plain message, instead of
# crashing later with an unreadable one.
set -eu

refuse() {
    {
        echo "TaskR cannot start: $1."
        echo "BROADCAST_CONNECTION=mercure starts the Mercure hub, which signs its tokens with this key."
        echo "Generate one and set it in your .env:   openssl rand -hex 32"
        echo "Real-time updates are optional: BROADCAST_CONNECTION=null starts without the hub."
    } >&2
    exit 1
}

# BROADCAST_CONNECTION=mercure starts the Mercure hub inside the web
# container, and every token it verifies is signed with MERCURE_JWT_SECRET.
# Left empty, Caddy aborts while provisioning the hub ("subscribers: a JWT
# key or the URL of a JWK Set must be provided"); shorter than the key size
# of HS256 (32 bytes), the application refuses it the first time it
# broadcasts. Whitespace is refused too: read by different dotenv parsers
# across the web and worker roles, it would sign tokens the hub rejects.
if [ "${BROADCAST_CONNECTION:-}" = "mercure" ]; then
    secret="${MERCURE_JWT_SECRET:-}"

    # A shell pattern, not grep: the value is never split into lines, so a
    # newline inside the secret is seen as well.
    case "$secret" in
        '') refuse "MERCURE_JWT_SECRET is missing" ;;
        *[[:space:][:cntrl:]]*) refuse "MERCURE_JWT_SECRET is malformed: whitespace is not allowed" ;;
    esac

    [ "${#secret}" -ge 32 ] || refuse "MERCURE_JWT_SECRET is malformed: expected at least 32 characters"
fi
