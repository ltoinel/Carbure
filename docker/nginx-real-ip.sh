#!/bin/sh
# Writes on stdout the configuration of nginx giving the address of the client behind the
# trusted reverse proxies (module realip), from TRUSTED_PROXIES: their addresses or
# networks (CIDR), separated by spaces or commas. Run by docker/entrypoint.sh, which
# writes it to /run/carbure/nginx-real-ip.conf (included by docker/nginx.conf).
#
# - unset: the private networks and the loopback, where the reverse proxy of a NAS, of
#   Docker (Caddy, Traefik) or of the host is;
# - empty or "none": no proxy is trusted, the address of the connection is kept (Carbure
#   published directly, without a proxy);
# - otherwise: only these proxies (e.g. "172.18.0.0/16" or "192.168.1.10").
#
# The address of the client is then the last one of X-Forwarded-For that is not a trusted
# proxy (real_ip_recursive): the proxy adds the address it sees, the addresses written
# before by the client are ignored. Exits with 1 on an invalid entry (the container does
# not start with a wrong setting).
set -eu
# The entries are never expanded as file names
set -f

DEFAULT_TRUSTED_PROXIES="127.0.0.0/8 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 ::1/128 fc00::/7"

# Whether $1 is an IPv4 address (four numbers from 0 to 255)
ipv4() {
    case "$1" in ''|*[!0-9.]*|.*|*.|*..*) return 1 ;; esac
    old_ifs=$IFS
    IFS=.
    # shellcheck disable=SC2086
    set -- $1
    IFS=$old_ifs
    [ $# -eq 4 ] || return 1
    for octet in "$@"; do
        [ ${#octet} -le 3 ] && [ "$octet" -le 255 ] || return 1
    done
}

# Whether $1 is an IPv6 address (hexadecimal groups, "::", an IPv4 at the end)
ipv6() {
    case "$1" in *:*) ;; *) return 1 ;; esac
    case "$1" in *[!0-9A-Fa-f:.]*|*:::*) return 1 ;; esac
    [ ${#1} -le 45 ]
}

# Whether $1 is a prefix length from 0 to $2
prefix() {
    case "$1" in ''|*[!0-9]*) return 1 ;; esac
    [ ${#1} -le 3 ] && [ "$1" -le "$2" ]
}

proxies=$(printf '%s' "${TRUSTED_PROXIES-$DEFAULT_TRUSTED_PROXIES}" | tr ',' ' ')
# shellcheck disable=SC2086
[ "$(echo $proxies)" != none ] || proxies=

echo "# Written by docker/entrypoint.sh from TRUSTED_PROXIES: do not edit"
trusted=0
for entry in $proxies; do
    address=${entry%%/*}
    if ipv4 "$address"; then
        max=32
    elif ipv6 "$address"; then
        max=128
    else
        echo "Carbure: invalid address in TRUSTED_PROXIES: $entry (expected IP addresses or networks, e.g. 192.168.1.10 or 172.16.0.0/12)" >&2
        exit 1
    fi
    case "$entry" in
        */*) prefix "${entry#*/}" "$max" || {
                echo "Carbure: invalid network in TRUSTED_PROXIES: $entry (prefix from 0 to $max)" >&2
                exit 1
            } ;;
    esac
    echo "set_real_ip_from $entry;"
    trusted=$((trusted + 1))
done

if [ "$trusted" -eq 0 ]; then
    echo "# No trusted proxy: the address of the client is the one of the connection"
else
    echo "real_ip_header X-Forwarded-For;"
    echo "real_ip_recursive on;"
fi
