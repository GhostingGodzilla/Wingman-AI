#!/usr/bin/env bash
# Offline contract tests for install.sh. Network, Linux identity, and Pelican
# execution are substituted; actual PHP parses JSON and verifies the checksum.
set -Eeuo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
php_binary="${PHP_BIN:-$(command -v php)}"
case "$php_binary" in /*) ;; *) php_binary="$(command -v "$php_binary")" ;; esac
test_dir="$(mktemp -d "${TMPDIR:-/tmp}/wingman-installer-test.XXXXXXXX")"
trap 'rm -rf -- "$test_dir"' EXIT
export WINGMAN_TEST_DIR="$test_dir" WINGMAN_TEST_PHP="$php_binary"
mkdir -p "$test_dir/bin" "$test_dir/panel with spaces/plugins" "$test_dir/panel with spaces/vendor" \
    "$test_dir/panel with spaces/bootstrap/cache" "$test_dir/panel with spaces/storage"
touch "$test_dir/panel with spaces/artisan" "$test_dir/panel with spaces/vendor/autoload.php" "$test_dir/panel with spaces/bootstrap/app.php"
printf 'offline package fixture\n' >"$test_dir/package.zip"
printf '<?php // downloaded helper fixture\n' >"$test_dir/helper.php"
"$php_binary" -r '$d=$argv[1]; file_put_contents($d."/manifest.json",json_encode(["version"=>"0.3.0","panel_version"=>"1.0.0-rc.1","download_url"=>"https://raw.githubusercontent.com/GhostingGodzilla/Wingman-AI/main/dist/wingman-ai.zip","sha256"=>hash_file("sha256",$d."/package.zip"),"release_notes_url"=>"https://github.com/GhostingGodzilla/Wingman-AI/blob/main/CHANGELOG.md"]));' "$test_dir"

cat >"$test_dir/bin/uname" <<'STUB'
#!/usr/bin/env bash
printf 'Linux\n'
STUB
cat >"$test_dir/bin/stat" <<'STUB'
#!/usr/bin/env bash
if [[ "${WINGMAN_BAD_OWNER:-0}" == 1 ]]; then printf '0\n'; else printf '1001\n'; fi
STUB
cat >"$test_dir/bin/id" <<'STUB'
#!/usr/bin/env bash
case "$*" in
  '-u') printf '0\n' ;;
  '-u paneltest'|'-g paneltest') printf '1001\n' ;;
  '-nu 1001') printf 'paneltest\n' ;;
  *) exit 1 ;;
esac
STUB
cat >"$test_dir/bin/runuser" <<'STUB'
#!/usr/bin/env bash
printf 'runner %s %s %s\n' "$1" "$2" "$3" >>"$WINGMAN_TEST_DIR/actions"
[[ "$1" == -u && "$2" == paneltest && "$3" == -- ]] || exit 90
shift 3
exec "$@"
STUB
cat >"$test_dir/bin/php" <<'STUB'
#!/usr/bin/env bash
if [[ "$1" == */pelican-install.php ]]; then
    printf 'helper %s\n' "$2" >>"$WINGMAN_TEST_DIR/actions"
    case "$2" in
      preflight) printf 'Offline Pelican preflight checked.\n'; exit "${WINGMAN_PREFLIGHT_EXIT:-0}" ;;
      install)
        [[ "$3" == "$WINGMAN_TEST_DIR/panel with spaces" && -f "$4" && -f "$5" ]] || exit 91
        printf 'Installed Wingman AI 0.3.0 (enabled).\n'; exit 0 ;;
    esac
    exit 92
fi
exec "$WINGMAN_TEST_PHP" "$@"
STUB
cat >"$test_dir/bin/curl" <<'STUB'
#!/usr/bin/env bash
url='' output=''
while (($#)); do
  case "$1" in
    --output) output="$2"; shift 2 ;;
    --retry|--connect-timeout|--max-time|--proto|--proto-redir|--user-agent) shift 2 ;;
    --*) shift ;;
    *) url="$1"; shift ;;
  esac
done
printf '%s\n' "$url" >>"$WINGMAN_TEST_DIR/requests"
commit='1111111111111111111111111111111111111111'
case "$url" in
  https://api.github.com/repos/GhostingGodzilla/Wingman-AI/commits/*)
    printf '{"sha":"%s"}' "$commit" >"$output" ;;
  "https://raw.githubusercontent.com/GhostingGodzilla/Wingman-AI/$commit/dist/release.json")
    cp "$WINGMAN_TEST_DIR/manifest.json" "$output" ;;
  "https://raw.githubusercontent.com/GhostingGodzilla/Wingman-AI/$commit/scripts/pelican-install.php")
    cp "$WINGMAN_TEST_DIR/helper.php" "$output" ;;
  "https://raw.githubusercontent.com/GhostingGodzilla/Wingman-AI/$commit/dist/wingman-ai.zip")
    if [[ "${WINGMAN_CORRUPT_ZIP:-0}" == 1 ]]; then printf 'corrupt\n' >"$output"; else cp "$WINGMAN_TEST_DIR/package.zip" "$output"; fi ;;
  *) printf 'Unexpected network URL: %s\n' "$url" >&2; exit 93 ;;
esac
STUB
chmod +x "$test_dir/bin/"*
export PATH="$test_dir/bin:$PATH"

passed=0
assert_contains() { [[ "$(cat "$1")" == *"$2"* ]] || { printf 'FAIL: expected %s in %s\n' "$2" "$1" >&2; exit 1; }; }
assert_lacks() { [[ ! -f "$1" || "$(cat "$1")" != *"$2"* ]] || { printf 'FAIL: unexpected %s in %s\n' "$2" "$1" >&2; exit 1; }; }
reset_logs() { : >"$test_dir/actions"; : >"$test_dir/requests"; }
pass() { passed=$((passed + 1)); printf 'PASS %s\n' "$1"; }

reset_logs
bash "$root/install.sh" --panel "$test_dir/panel with spaces" --yes >"$test_dir/output" 2>&1
assert_contains "$test_dir/actions" 'runner -u paneltest --'
assert_contains "$test_dir/actions" 'helper preflight'
assert_contains "$test_dir/actions" 'helper install'
assert_contains "$test_dir/requests" '1111111111111111111111111111111111111111/dist/wingman-ai.zip'
assert_lacks "$test_dir/requests" '/main/dist/wingman-ai.zip'
pass 'install uses one immutable commit and the non-root panel account; paths with spaces survive'

reset_logs
bash "$root/install.sh" --panel "$test_dir/panel with spaces" --check >"$test_dir/output" 2>&1
assert_contains "$test_dir/output" 'Available: Wingman AI 0.3.0'
assert_lacks "$test_dir/actions" 'helper'
assert_lacks "$test_dir/requests" 'wingman-ai.zip'
pass '--check downloads metadata only and never runs Pelican'

reset_logs
if WINGMAN_CORRUPT_ZIP=1 bash "$root/install.sh" --panel "$test_dir/panel with spaces" --yes >"$test_dir/output" 2>&1; then exit 1; fi
assert_contains "$test_dir/output" 'ZIP SHA-256 does not match'
assert_lacks "$test_dir/actions" 'helper install'
pass 'checksum mismatch stops before plugin installation'

reset_logs
if WINGMAN_PREFLIGHT_EXIT=1 bash "$root/install.sh" --panel "$test_dir/panel with spaces" --yes >"$test_dir/output" 2>&1; then exit 1; fi
assert_contains "$test_dir/output" 'Pelican checks failed'
assert_lacks "$test_dir/requests" 'wingman-ai.zip'
assert_lacks "$test_dir/actions" 'helper install'
pass 'failed compatibility checks stop before the package download'

reset_logs
if WINGMAN_BAD_OWNER=1 bash "$root/install.sh" --panel "$test_dir/panel with spaces" --yes >"$test_dir/output" 2>&1; then exit 1; fi
assert_contains "$test_dir/output" 'Cannot safely determine the Pelican account'
assert_lacks "$test_dir/actions" 'helper'
pass 'ambiguous root-owned runtime folders require an explicit service account'

reset_logs
WINGMAN_BAD_OWNER=1 bash "$root/install.sh" --panel "$test_dir/panel with spaces" --user paneltest --yes >"$test_dir/output" 2>&1
assert_contains "$test_dir/actions" 'helper install'
pass '--user supports custom ownership layouts'

reset_logs
bash "$root/install.sh" --panel "$test_dir/panel with spaces" --version 0.3.0 --check >"$test_dir/output" 2>&1
assert_contains "$test_dir/requests" '/commits/v0.3.0'
pass 'numeric --version resolves the published v-prefixed tag'

reset_logs
if bash "$root/install.sh" --panel "$test_dir/panel with spaces" --version v0.4.0 --check >"$test_dir/output" 2>&1; then exit 1; fi
assert_contains "$test_dir/output" 'requested tag and release version do not match'
pass 'a mismatched requested tag is rejected'

reset_logs
if bash "$root/install.sh" --panel "$test_dir/panel with spaces" --version '../main' >"$test_dir/output" 2>&1; then exit 1; fi
assert_lacks "$test_dir/requests" 'https://'
pass 'unsafe refs are rejected before network access'

printf '%s offline installer shell checks passed.\n' "$passed"
