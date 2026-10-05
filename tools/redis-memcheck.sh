#!/bin/sh
# Redis memory/health report for the object cache. Usage: tools/redis-memcheck.sh
# Run from the repo root (uses docker compose). Exits 1 when memory is above 80% of maxmemory.
set -eu
R() { docker compose exec -T redis sh -c 'redis-cli -a "$REDIS_PASSWORD" --no-auth-warning "$@"' -- "$@"; }

echo "== memory"
R INFO memory | grep -E '^(used_memory_human|used_memory_peak_human|maxmemory_human|maxmemory_policy|mem_fragmentation_ratio)'
echo "== stats"
R INFO stats | grep -E '^(keyspace_hits|keyspace_misses|evicted_keys|expired_keys)'
echo "== keyspace"
R INFO keyspace
echo "== key families (sampled 5000 keys, count by prefix)"
R --scan --count 1000 --pattern 'meydan:*' | head -5000 | awk -F: '{print $2":"$3}' | sed 's/[0-9a-f]\{16,\}/<hash>/g' | sort | uniq -c | sort -rn | head -20

USED=$(R INFO memory | awk -F: '/^used_memory:/{print $2+0}')
MAX=$(R CONFIG GET maxmemory | tail -1)
HITS=$(R INFO stats | awk -F: '/^keyspace_hits:/{print $2+0}')
MISS=$(R INFO stats | awk -F: '/^keyspace_misses:/{print $2+0}')
[ "$((HITS + MISS))" -gt 0 ] && echo "hit ratio: $((100 * HITS / (HITS + MISS)))%"
if [ "$MAX" -gt 0 ] && [ "$((USED * 100 / MAX))" -ge 80 ]; then
  echo "WARNING: Redis memory above 80% of maxmemory" >&2
  exit 1
fi
