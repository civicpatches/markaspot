#!/usr/bin/env bash
# Copy this runner unchanged to another Drupal repo and supply scripts/test-isolated.conf.
# Only the config defines the project layout, images, fixtures and services.
set -euo pipefail

usage() {
  cat <<'HELP'
Usage: scripts/test-isolated.sh [--groups a,b,c] [--filter PATTERN]
       [--testsuite unit,kernel,functional] [--jobs [N]]
       [--vendor PATH] [--output-dir PATH]

--jobs without N uses half of Docker's CPUs, capped at 8 (the default).
--vendor accepts a Composer checkout or its vendor directory; the lockfiles
must match byte for byte. Logs and JUnit files go into a new run directory.
HELP
}

die() { printf '%s\n' "$*" >&2; exit 1; }

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
groups='' filter='' suites='' jobs='' vendor='' output_parent=${TMPDIR:-/tmp}
has_groups=false
while (($#)); do
  case "$1" in
    -h|--help) usage; exit 0 ;;
    --jobs)
      jobs=
      if [[ ${2:-} != -* && -n ${2:-} ]]; then jobs=$2; shift; fi ;;
    --groups|--filter|--testsuite|--vendor|--output-dir)
      [[ $# -ge 2 && -n $2 ]] || die "Missing value for $1"
      case "$1" in
        --groups) groups=$2; has_groups=true ;;
        --filter) filter=$2 ;;
        --testsuite) suites=$2 ;;
        --vendor) vendor=$2 ;;
        --output-dir) output_parent=$2 ;;
      esac
      shift ;;
    *) die "Unknown argument: $1" ;;
  esac
  shift
done
[[ -z $jobs ]] || { [[ $jobs =~ ^[1-9][0-9]{0,2}$ ]] && ((jobs <= 126)); } || die '--jobs requires an integer from 1 to 126 (two containers per worker in a /24).'

PROJECT_PREFIX=
DEFAULT_GROUPS=
COMPOSER_LOCK="$root/composer.lock"
PHP_BIN=php
TEST_WORKDIR=.
DEPENDENCY_PATHS=()
OPTIONAL_DEPENDENCY_PATHS=()
DEPENDENCY_CHECKOUTS=()
EXTRA_MOUNTS=()
SOURCE_EXCLUDES=()
TEST_ENV=()
# shellcheck disable=SC2329 # Serialized into the container script.
start_test_services() { :; }
# shellcheck source=/dev/null
source "$root/scripts/test-isolated.conf"
: "${PHP_IMAGE:?Set PHP_IMAGE}" "${DB_IMAGE:?Set DB_IMAGE}" "${DOCROOT:?Set DOCROOT}" "${PHPUNIT_CONFIG:?Set PHPUNIT_CONFIG}"
network_prefix=${ISOLATED_NETWORK_PREFIX:-10.254.240}
[[ $network_prefix =~ ^([0-9]{1,3}\.){2}[0-9]{1,3}$ ]] || die 'Set ISOLATED_NETWORK_PREFIX to the first three octets of the first /24.'
# Validate octets before using them in arithmetic and reserve three adjacent /24s.
IFS=. read -r subnet_a subnet_b subnet_c <<< "$network_prefix"
((10#$subnet_a <= 255 && 10#$subnet_b <= 255 && 10#$subnet_c <= 253)) || die 'The three /24 networks must fit in one /16.'

[[ -f $COMPOSER_LOCK ]] || die "Reference composer.lock is missing: $COMPOSER_LOCK"
config_relative=${PHPUNIT_CONFIG#"${PROJECT_PREFIX:+$PROJECT_PREFIX/}"}
[[ -f $root/$config_relative ]] || die "PHPUnit config is missing: $root/$config_relative"

resolve_vendor() {
  local checkout path
  checkout=$(cd -- "$1" >/dev/null 2>&1 && pwd -P) || return 1
  if [[ $checkout == */vendor && ! -f $checkout/composer.lock ]]; then checkout=${checkout%/vendor}; fi
  [[ -f $checkout/vendor/bin/phpunit ]] && cmp -s "$COMPOSER_LOCK" "$checkout/composer.lock" || return 1
  for path in ${DEPENDENCY_PATHS[@]+"${DEPENDENCY_PATHS[@]}"}; do
    [[ -e $checkout/$path ]] || return 1
  done
  printf '%s\n' "$checkout"
}
if [[ -n $vendor ]]; then
  dependencies=$(resolve_vendor "$vendor") || die "No usable PHPUnit dependencies at $vendor. Expected byte-identical composer.lock and all configured dependency paths."
else
  dependencies=
  candidates=("$root")
  if worktrees=$(git -C "$root" -c core.quotePath=false worktree list --porcelain); then
    while IFS= read -r line; do
      [[ $line == 'worktree '* ]] && candidates+=("${line#worktree }")
    done <<< "$worktrees"
  fi
  for candidate in "${candidates[@]}" ${DEPENDENCY_CHECKOUTS[@]+"${DEPENDENCY_CHECKOUTS[@]}"}; do
    if dependencies=$(resolve_vendor "$candidate"); then break; fi
  done
  [[ -n $dependencies ]] || die 'No usable Composer checkout with byte-identical composer.lock. Install with Composer or supply --vendor PATH.'
fi

selection=()
if ! $has_groups && [[ -z $filter && -z $suites ]]; then groups=$DEFAULT_GROUPS; fi
if [[ -n $groups ]]; then
  IFS=, read -r -a group_names <<< "$groups"
  for group in "${group_names[@]}"; do selection+=(--group "$group"); done
fi
if [[ -n $filter ]]; then selection+=(--filter "$filter"); fi

mounts=(--mount "type=bind,src=$root,dst=/source,readonly")
dependency_mounts=()
dependency_paths=()
excludes=(.git .ddev)
add_mount() {
  local source=$1 destination=$2 kind=${3:-extra}
  [[ -e $source ]] || die "Missing bind mount source: $source"
  # The directory component is canonical so Docker Desktop sees the real path.
  source=$(cd "$(dirname "$source")" && pwd -P)/$(basename "$source")
  if [[ $kind == dependency ]]; then
    dependency_mounts+=(--mount "type=bind,src=$source,dst=/inputs/$destination,readonly")
    dependency_paths+=("$destination")
  else
    mounts+=(--mount "type=bind,src=$source,dst=/app/$destination,readonly")
  fi
  if [[ $destination == "${PROJECT_PREFIX:+$PROJECT_PREFIX/}"* ]]; then
    excludes+=("${destination#"${PROJECT_PREFIX:+$PROJECT_PREFIX/}"}")
  fi
}
add_mount "$dependencies/vendor" vendor dependency
for path in ${DEPENDENCY_PATHS[@]+"${DEPENDENCY_PATHS[@]}"} ${OPTIONAL_DEPENDENCY_PATHS[@]+"${OPTIONAL_DEPENDENCY_PATHS[@]}"}; do
  if [[ -e $dependencies/$path ]]; then add_mount "$dependencies/$path" "$path" dependency; fi
done
for mount in ${EXTRA_MOUNTS[@]+"${EXTRA_MOUNTS[@]}"}; do
  add_mount "${mount%%|*}" "${mount#*|}"
done
excludes+=( ${SOURCE_EXCLUDES[@]+"${SOURCE_EXCLUDES[@]}"} )

for image in "$PHP_IMAGE" "$DB_IMAGE"; do
  docker image inspect "$image" >/dev/null || die "Image unavailable locally: $image. Run docker pull first."
done
if [[ -z $jobs ]]; then
  cores=$(docker info --format '{{.NCPU}}')
  jobs=$((cores > 16 ? 8 : (cores > 1 ? cores / 2 : 1)))
fi
command -v python3 >/dev/null || die 'python3 is required to read PHPUnit XML.'
mkdir -p "$output_parent"
output=$(mktemp -d "$(cd "$output_parent" && pwd -P)/isolated-tests.XXXXXX")
# mktemp's random suffix keeps container names unique across hosts and runners.
run="isolated-tests-${output##*.}"
network="$run-net"
network_id=
cache="$run-dependencies"
cache_created=false
containers=()
workers=()
# shellcheck disable=SC2329 # Called by the EXIT trap.
cleanup() {
  local status=$? container worker
  trap - EXIT
  trap '' HUP INT TERM
  for worker in ${workers[@]+"${workers[@]}"}; do kill "$worker" 2>/dev/null || true; done
  for container in ${containers[@]+"${containers[@]}"}; do
    if docker container inspect "$container" >/dev/null 2>&1; then
      docker rm -fv "$container" >/dev/null || printf 'Could not remove container %s\n' "$container" >&2
    fi
  done
  for worker in ${workers[@]+"${workers[@]}"}; do wait "$worker" 2>/dev/null || true; done
  if [[ -n $network_id ]] || [[ $(docker network inspect --format '{{index .Labels "local.isolated-tests"}}' "$network" 2>/dev/null) == "$run" ]]; then
    docker network rm "$network" >/dev/null || printf 'Could not remove network %s\n' "$network" >&2
  fi
  if $cache_created; then
    docker volume rm "$cache" >/dev/null || printf 'Could not remove tmpfs volume %s\n' "$cache" >&2
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

reserve_network() {
  local process_args heavy occupied slot subnet message
  while :; do
    process_args=$(ps -eo args)
    heavy=$(grep -cE '[p]hpunit|[v]itest|[u]pdate-gate/run.sh' <<< "$process_args" || true)
    occupied=$(docker network ls --filter label=local.isolated-tests --format '{{.ID}}' | awk 'END {print NR}')
    if ((heavy + occupied >= 3)); then
      printf 'Waiting for a test slot (%s host processes, %s isolated runs).\n' "$heavy" "$occupied"
      sleep 10
      continue
    fi
    docker stats --no-stream --format '{{.Name}} {{.CPUPerc}} {{.MemUsage}}' > "$output/docker-stats.txt"
    process_args=$(ps -eo args)
    heavy=$(grep -cE '[p]hpunit|[v]itest|[u]pdate-gate/run.sh' <<< "$process_args" || true)
    occupied=$(docker network ls --filter label=local.isolated-tests --format '{{.ID}}' | awk 'END {print NR}')
    if ((heavy + occupied >= 3)); then continue; fi
    for slot in 0 1 2; do
      subnet="$((10#$subnet_a)).$((10#$subnet_b)).$((10#$subnet_c + slot)).0/24"
      if message=$(docker network create --internal --subnet "$subnet" --label "local.isolated-tests=$run" "$network" 2>&1); then
        network_id=$message
        printf 'Reserved %s (%s), %s host processes, %s other isolated runs.\n' "$network" "$subnet" "$heavy" "$occupied"
        return
      fi
      # Docker's allocator checks all overlapping pools atomically, including
      # larger subnets and concurrent attempts to reserve the same /24.
      [[ $message == *overlap* ]] || die "Cannot create isolated network $subnet: $message"
    done
    if ! docker network ls --filter label=local.isolated-tests --format '{{.ID}}' | grep . >/dev/null; then
      die 'All runner /24s overlap existing Docker nets. Set ISOLATED_NETWORK_PREFIX to a free block of three /24s.'
    fi
    printf 'Waiting for a free runner /24.\n'
    sleep 10
  done
}

stage_dependencies() {
  local holder="$run-deps" path
  # Copy once over Desktop's host filesystem bridge. The holder keeps the
  # volume mounted so its tmpfs survives gaps between discovery and workers.
  docker volume create --opt type=tmpfs --opt device=tmpfs --opt o=mode=0755 "$cache" >/dev/null
  cache_created=true
  containers+=("$holder")
  docker run --pull never --name "$holder" --network none --read-only --detach \
    --mount "type=volume,src=$cache,dst=/cache,volume-nocopy" "${dependency_mounts[@]}" \
    --entrypoint /bin/sleep "$PHP_IMAGE" infinity >/dev/null
  docker exec "$holder" bash -o pipefail -c 'tar -C /inputs -cf - "$@" | tar -C /cache -xf -' -- "${dependency_paths[@]}"
  for path in "${dependency_paths[@]}"; do
    mounts+=(--mount "type=volume,src=$cache,dst=/app/$path,volume-subpath=$path,readonly,volume-nocopy")
  done
}

# Container-side bootstrap. Pass it on stdin so the host process count tracks
# whole invocations via their reserved network, independent of worker count.
container_script=$(declare -f start_test_services)
container_script+=$(cat <<'CONTAINER'

set -euo pipefail
mode=$1
shift
if [[ $mode == stage ]]; then
  mkdir -p "/app/$PROJECT_PREFIX" "/app/$DOCROOT/sites/default" "/app/$DOCROOT/sites/simpletest"
  tar -C /source --null --no-recursion -T - -cf - | tar -C "/app/$PROJECT_PREFIX" -xf -
  exit
fi
cd "/app/$TEST_WORKDIR"
PHPUNIT_CONFIG="/app/$PHPUNIT_CONFIG"
if [[ $mode == discover ]]; then
  exec "$PHP_BIN" /app/vendor/bin/phpunit -c "$PHPUNIT_CONFIG" --do-not-cache-result --list-tests-xml /tmp/tests.xml "$@"
fi
if [[ $mode == shard ]]; then
  PHPUNIT_CONFIG="$(dirname "$PHPUNIT_CONFIG")/.isolated-shard.xml"
  cat > "$PHPUNIT_CONFIG"
fi
start_test_services
"$PHP_BIN" -S 127.0.0.1:8080 -t "/app/$DOCROOT" "/app/$DOCROOT/.ht.router.php" > /tmp/php-server.log 2>&1 &
for attempt in {1..20}; do
  if curl --silent --output /dev/null http://127.0.0.1:8080/; then break; fi
  sleep 1
done
curl --silent --output /dev/null http://127.0.0.1:8080/ || { cat /tmp/php-server.log >&2; exit 1; }
exec "$PHP_BIN" /app/vendor/bin/phpunit -c "$PHPUNIT_CONFIG" --do-not-cache-result --colors=never --log-junit /tmp/junit.xml "$@"
CONTAINER
)

source_files() {
  local path
  local pathspecs=(.)
  for path in "${excludes[@]}"; do pathspecs+=(":(exclude)$path"); done
  git -C "$root" ls-files -z --cached --others --exclude-standard -- "${pathspecs[@]}" |
    while IFS= read -r -d '' path; do
      if [[ -f $root/$path || -L $root/$path ]]; then printf '%s\0' "$path"; fi
    done
}

create_runner() {
  local runner=$1 db=$2 env_args=() entry
  for entry in ${TEST_ENV[@]+"${TEST_ENV[@]}"}; do env_args+=(--env "$entry"); done
  containers+=("$runner")
  docker create --pull never --name "$runner" --network "$network" --read-only --init \
    --tmpfs /app:rw,exec --tmpfs /tmp:rw,exec,mode=1777 \
    "${mounts[@]}" ${env_args[@]+"${env_args[@]}"} \
    -e HOME=/tmp -e TMPDIR=/tmp -e BROWSERTEST_OUTPUT_FILE=/tmp/browser-output \
    -e SIMPLETEST_BASE_URL=http://127.0.0.1:8080 -e "SIMPLETEST_DB=mysql://isolated:isolated@$db/isolated" \
    -e "PHP_BIN=$PHP_BIN" -e "DOCROOT=$DOCROOT" -e "PROJECT_PREFIX=$PROJECT_PREFIX" \
    -e "PHPUNIT_CONFIG=$PHPUNIT_CONFIG" -e "TEST_WORKDIR=$TEST_WORKDIR" \
    --entrypoint /bin/bash "$PHP_IMAGE" -c 'exec sleep infinity' >/dev/null
}

start_runner() {
  local runner=$1
  docker start "$runner" >/dev/null
  docker exec -i "$runner" bash -c 'cat > /tmp/isolated-run' <<< "$container_script"
  source_files | docker exec -i "$runner" bash /tmp/isolated-run stage
}

create_database() {
  local db=$1
  containers+=("$db")
  docker create --pull never --name "$db" --network "$network" --read-only \
    --tmpfs /var/lib/mysql:rw,size=1g --tmpfs /run/mysqld --tmpfs /tmp \
    --env MARIADB_ROOT_PASSWORD=isolated-root --env MARIADB_DATABASE=isolated \
    --env MARIADB_USER=isolated --env MARIADB_PASSWORD=isolated \
    "$DB_IMAGE" --innodb-flush-log-at-trx-commit=2 --skip-log-bin >/dev/null
}

wait_database() {
  local db=$1 attempt
  for ((attempt=0; attempt<90; attempt++)); do
    if docker exec "$db" mariadb-admin ping --protocol=TCP -h 127.0.0.1 --silent >/dev/null 2>&1 &&
      docker exec "$db" mariadb -uisolated -pisolated --protocol=TCP -h 127.0.0.1 isolated -e 'SELECT 1' >/dev/null 2>&1; then
      return
    fi
    sleep 1
  done
  docker logs "$db" >&2
  die "MariaDB readiness timed out: $db"
}

plan_shards() {
  python3 - "$output" "$root/$config_relative" "$jobs" <<'PYTHON'
import copy
import sys
from pathlib import Path
from xml.etree import ElementTree as ET

output, config = map(Path, sys.argv[1:3])
requested = int(sys.argv[3])
listing = ET.parse(output / 'tests.xml')
files = {}
for item in listing.findall('./{*}tests/*'):
    path = item.attrib['file']
    # Kernel and functional tests spend most of their time installing Drupal.
    multiplier = 400 if '/Functional' in path else 250 if '/Kernel/' in path else 1
    files[path] = files.get(path, 0) + multiplier * max(1, len(item))

count = min(requested, len(files))
if count < 2:
    print(1)
    sys.exit(0)

loads = [0] * count
shards = [[] for _ in loads]
for path in sorted(files, key=lambda path: (-files[path], path)):
    index = loads.index(min(loads))
    shards[index].append(path)
    loads[index] += files[path]

original = ET.parse(config).getroot()
for index, paths in enumerate(shards, 1):
    root = copy.deepcopy(original)
    suites = root.find('testsuites')
    if suites is None:
        suites = ET.SubElement(root, 'testsuites')
    suites.clear()
    suite = ET.SubElement(suites, 'testsuite', name='isolated')
    for path in sorted(paths):
        ET.SubElement(suite, 'file').text = path
    ET.ElementTree(root).write(output / f'shard-{index}.config.xml', encoding='utf-8', xml_declaration=True)
print(count)
PYTHON
}

run_worker() {
  local index=$1 mode=$2 runner=$3 db=$4
  local begun=$SECONDS status=0
  local log="$output/shard-$index.log" report="$output/shard-$index.xml"
  shift 4
  if ! wait_database "$db"; then return 1; fi
  if [[ $mode == shard ]]; then
    docker exec -i "$runner" bash /tmp/isolated-run shard "$@" < "$output/shard-$index.config.xml" > "$log" 2>&1 || status=$?
  else
    docker exec "$runner" bash /tmp/isolated-run serial "$@" > "$log" 2>&1 || status=$?
  fi
  # docker cp does not see files on a container's tmpfs mounts.
  if ! docker exec "$runner" test -s /tmp/junit.xml || ! docker exec "$runner" cat /tmp/junit.xml > "$report"; then
    printf 'Shard %s has no JUnit report. See %s\n' "$index" "$log" >&2
    if ((status == 0)); then status=1; fi
  fi
  printf '%s %s\n' "$status" "$((SECONDS - begun))" > "$output/shard-$index.status"
  return "$status"
}

printf 'Output: %s\nDependencies: %s\n' "$output" "$dependencies"
begun=$SECONDS
reserve_network
stage_dependencies
all_selection=( ${selection[@]+"${selection[@]}"} )
if [[ -n $suites ]]; then all_selection+=(--testsuite "$suites"); fi
shards=1
if ((jobs > 1)); then
  discovery="$run-discover"
  create_runner "$discovery" "$run-db-1"
  start_runner "$discovery"
  discover_status=0
  docker exec "$discovery" bash /tmp/isolated-run discover ${all_selection[@]+"${all_selection[@]}"} > "$output/discovery.log" 2>&1 || discover_status=$?
  if ((discover_status)); then cat "$output/discovery.log" >&2; exit "$discover_status"; fi
  docker exec "$discovery" cat /tmp/tests.xml > "$output/tests.xml"
  shards=$(plan_shards)
  docker rm -fv "$discovery" >/dev/null
fi

mode=serial
if ((shards > 1)); then mode=shard; all_selection=( ${selection[@]+"${selection[@]}"} ); fi
printf 'Running %s PHPUnit shard(s).\n' "$shards"
for ((index=1; index<=shards; index++)); do
  create_database "$run-db-$index"
  create_runner "$run-php-$index" "$run-db-$index"
  docker start "$run-db-$index" >/dev/null
  start_runner "$run-php-$index"
  run_worker "$index" "$mode" "$run-php-$index" "$run-db-$index" ${all_selection[@]+"${all_selection[@]}"} &
  workers+=("$!")
done

status=0
for worker in "${workers[@]}"; do
  code=0
  wait "$worker" || code=$?
  if ((code > status)); then status=$code; fi
done
workers=()
python3 - "$output" "$shards" "$status" "$((SECONDS - begun))" <<'PYTHON'
import sys
from pathlib import Path
from xml.etree import ElementTree as ET

output = Path(sys.argv[1])
shards, status, elapsed = map(int, sys.argv[2:])
labels = ('tests', 'assertions', 'failures', 'errors', 'skipped')
totals = dict.fromkeys(labels, 0)

def counts(values):
    return ', '.join(f'{values[label]} {label}' for label in labels)

for index in range(1, shards + 1):
    stem = output / f'shard-{index}'
    try:
        code, seconds = stem.with_suffix('.status').read_text().split()
    except FileNotFoundError:
        print(f'Shard {index}: startup failed (no result)')
        continue
    try:
        root = ET.parse(stem.with_suffix('.xml')).getroot()
    except (FileNotFoundError, ET.ParseError):
        print(f'Shard {index}: exit {code}, {seconds}s, no readable JUnit report')
        continue
    suites = [root] if root.tag == 'testsuite' else root.findall('testsuite')
    metrics = {label: sum(int(suite.get(label, 0)) for suite in suites) for label in labels}
    for label in labels:
        totals[label] += metrics[label]
    print(f'Shard {index}: exit {code}, {seconds}s, {counts(metrics)}')
print(f'Overall: exit {status}, {elapsed}s, {counts(totals)}')
PYTHON
exit "$status"
