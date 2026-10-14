#!/usr/bin/env bash
# Wingman AI installer/updater. Run on the Linux machine hosting Pelican Panel.
set -Eeuo pipefail
umask 022

REPOSITORY='GhostingGodzilla/Wingman-AI'
RAW_BASE="https://raw.githubusercontent.com/$REPOSITORY"
panel=''
panel_user=''
requested_ref='main'
requested_version=''
noninteractive=0
check_only=0
force=0
work=''

die() { printf 'Wingman AI: %s\n' "$*" >&2; exit 1; }
usage() {
    cat <<'HELP'
Wingman AI — install or update the Pelican plugin on Linux

Usage: sudo bash install.sh [options]
  --panel PATH    Pelican Panel folder (default: /var/www/pelican)
  --user USER     Non-root account that runs/writes Pelican (auto-detected)
  --version REF   Install a published version (e.g. v0.3.0), tag, or commit
  --yes          Use defaults without an interactive path prompt
  --check        Show the available and installed version; change nothing
  --force        Reinstall the same version, or explicitly allow a downgrade
  --help         Show this help

Run the same command again to update. Existing disabled/enabled state and
private AI settings are preserved. A previous plugin copy is kept as a backup.
Requires an already installed, compatible Pelican Panel and PHP CLI with ZIP.
This command does not install Linux packages or upgrade Pelican.
HELP
}

while (($#)); do
    case "$1" in
        --panel|--user|--version)
            (($# >= 2)) && [[ -n "$2" && "$2" != --* ]] || die "Missing value for $1."
            case "$1" in
                --panel) panel="$2" ;;
                --user) panel_user="$2" ;;
                --version) requested_ref="$2"; requested_version="$2" ;;
            esac
            shift 2 ;;
        --yes|-y) noninteractive=1; shift ;;
        --check) check_only=1; shift ;;
        --force) force=1; shift ;;
        --help|-h) usage; exit 0 ;;
        *) die "Unknown option: $1. Use --help." ;;
    esac
done

[[ "$(uname -s)" == Linux ]] || die 'Run this installer on the Linux machine hosting Pelican Panel.'
for dependency in curl php mktemp stat realpath id; do
    command -v "$dependency" >/dev/null 2>&1 || die "Missing $dependency. Install it first, then retry."
done
[[ "$requested_ref" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]] || die 'Use a version tag or commit without slashes or spaces.'
if [[ "$requested_ref" =~ ^[0-9]+\.[0-9]+\.[0-9]+ ]]; then requested_ref="v$requested_ref"; fi

if [[ -z "$panel" ]]; then
    if [[ -f /var/www/pelican/artisan ]]; then
        panel='/var/www/pelican'
    elif ((check_only)); then
        panel='/var/www/pelican'
    elif ((!noninteractive)) && [[ -r /dev/tty && -w /dev/tty ]]; then
        printf 'Pelican folder (for example /var/www/pelican): ' >/dev/tty
        IFS= read -r panel </dev/tty || die 'Could not read the panel folder. Pass --panel PATH.'
    else
        die 'Pelican was not found at /var/www/pelican. Add --panel /path/to/pelican.'
    fi
fi
[[ -n "$panel" ]] || die 'Pass --panel /path/to/pelican.'

work="$(mktemp -d "${TMPDIR:-/tmp}/wingman-ai.XXXXXXXX")"
cleanup() {
    # Delete only the private directory created by mktemp in this invocation.
    if [[ -n "$work" && -d "$work" && ! -L "$work" && "${work##*/}" == wingman-ai.* ]]; then
        rm -rf -- "$work"
    fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

fetch() {
    curl --fail --silent --show-error --location --retry 2 --connect-timeout 15 --max-time 180 \
        --proto '=https' --proto-redir '=https' --user-agent 'Wingman-AI-Installer' "$1" --output "$2"
}

printf 'Checking Wingman AI release…\n'
fetch "https://api.github.com/repos/$REPOSITORY/commits/$requested_ref" "$work/commit.json" \
    || die "Cannot resolve $requested_ref on GitHub. Check the tag, network, or GitHub API rate limit."
commit="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); $s=$j["sha"]??""; if(!is_string($s)||!preg_match("/^[a-f0-9]{40}$/D",$s)){fwrite(STDERR,"Invalid GitHub commit response.\n");exit(1);} echo $s;' "$work/commit.json")" \
    || die 'Could not determine an immutable release commit.'
fetch "$RAW_BASE/$commit/dist/release.json" "$work/release.json" || die 'Could not download the release manifest.'

# Parse data as JSON, never as shell code. The helper and archive come from one
# immutable commit, so an update pushed during installation cannot mix versions.
php -r '
try {
    $j=json_decode(file_get_contents($argv[1]),true,16,JSON_THROW_ON_ERROR);
    foreach (["version","panel_version","download_url","sha256","release_notes_url"] as $key) {
        if (!isset($j[$key]) || !is_string($j[$key]) || preg_match("/[\\x00-\\x1f\\x7f]/",$j[$key])) throw new Exception("Invalid manifest field: ".$key);
    }
    if (!preg_match("/^[0-9]+\\.[0-9]+\\.[0-9]+(?:-[A-Za-z0-9.-]+)?$/D",$j["version"])) throw new Exception("Invalid release version.");
    if (!preg_match("/^[a-fA-F0-9]{64}$/D",$j["sha256"])) throw new Exception("Invalid SHA-256.");
    if (!str_starts_with($j["release_notes_url"],"https://github.com/GhostingGodzilla/Wingman-AI/")) throw new Exception("Invalid release notes URL.");
    $prefix="https://raw.githubusercontent.com/GhostingGodzilla/Wingman-AI/";
    if (str_starts_with($j["download_url"],$prefix) && preg_match("~^[A-Za-z0-9._-]+/dist/wingman-ai\\.zip$~D",substr($j["download_url"],strlen($prefix)))) {
        $url=$prefix.$argv[2]."/dist/wingman-ai.zip";
    } elseif (preg_match("~^https://github\\.com/GhostingGodzilla/Wingman-AI/releases/download/[A-Za-z0-9._-]+/wingman-ai\\.zip$~D",$j["download_url"])) {
        $url=$j["download_url"];
    } else throw new Exception("Unexpected package URL.");
    echo $j["version"],"\n",$j["panel_version"],"\n",$url,"\n",strtolower($j["sha256"]),"\n",$j["release_notes_url"],"\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
' "$work/release.json" "$commit" >"$work/release-fields" || die 'The release manifest is invalid.'
mapfile -t fields <"$work/release-fields"
version="${fields[0]}"
package_url="${fields[2]}"
if [[ -n "$requested_version" && "$requested_version" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+ ]]; then
    [[ "${requested_version#v}" == "$version" ]] || die 'The requested tag and release version do not match.'
fi
printf 'Available: Wingman AI %s (Pelican %s)\nRelease notes: %s\n' "$version" "${fields[1]}" "${fields[4]}"
if ((check_only)); then
    if [[ -f "$panel/plugins/wingman-ai/plugin.json" ]]; then
        php -r '$j=json_decode(file_get_contents($argv[1]),true); echo "Installed: ",$j["version"]??"unknown","\n";' "$panel/plugins/wingman-ai/plugin.json"
    else
        printf 'Installed: not found at %s\n' "$panel"
    fi
    exit 0
fi

panel="$(realpath -e -- "$panel")" || die 'The panel folder does not exist.'
[[ -f "$panel/artisan" && -f "$panel/vendor/autoload.php" && -f "$panel/bootstrap/app.php" ]] \
    || die "This is not an installed Pelican Panel: $panel"

# Runtime writable folders identify the service account even when artisan was
# unpacked by root. An override handles custom layouts without guessing.
if [[ -z "$panel_user" ]]; then
    [[ -d "$panel/storage" && -d "$panel/bootstrap/cache" ]] || die 'Panel storage/cache folders are missing.'
    storage_uid="$(stat -c %u -- "$panel/storage")"
    cache_uid="$(stat -c %u -- "$panel/bootstrap/cache")"
    artisan_uid="$(stat -c %u -- "$panel/artisan")"
    if [[ "$storage_uid" != 0 && "$storage_uid" == "$cache_uid" ]]; then
        panel_user="$(id -nu "$storage_uid")"
    elif [[ "$artisan_uid" != 0 && ( "$storage_uid" == "$artisan_uid" || "$cache_uid" == "$artisan_uid" ) ]]; then
        panel_user="$(id -nu "$artisan_uid")"
    else
        die 'Cannot safely determine the Pelican account. Add --user www-data (or your panel service account).'
    fi
fi
target_uid="$(id -u "$panel_user" 2>/dev/null)" || die "Unknown panel account: $panel_user"
[[ "$target_uid" != 0 ]] || die 'Choose the non-root account that runs Pelican with --user.'
php_binary="$(command -v php)"
runner=()
if [[ "$(id -u)" != "$target_uid" ]]; then
    [[ "$(id -u)" == 0 ]] || die "Run with sudo, or as $panel_user."
    if command -v runuser >/dev/null 2>&1; then runner=(runuser -u "$panel_user" --)
    elif command -v sudo >/dev/null 2>&1; then runner=(sudo -u "$panel_user" --)
    else die 'Missing runuser or sudo to run Pelican commands as its service account.'; fi
fi

# Only create the plugins folder when it is absent; reject existing symlinks.
[[ ! -L "$panel/plugins" ]] || die 'Refusing a symlinked plugins directory.'
if [[ ! -e "$panel/plugins" ]]; then
    mkdir -- "$panel/plugins"
    if [[ "$(id -u)" == 0 ]]; then chown "$target_uid:$(id -g "$panel_user")" -- "$panel/plugins"; fi
    chmod 0755 -- "$panel/plugins"
fi
[[ -d "$panel/plugins" ]] || die 'The plugins path is not a directory.'
fetch "$RAW_BASE/$commit/scripts/pelican-install.php" "$work/pelican-install.php" || die 'Could not download the Pelican install helper.'
chmod 0711 -- "$work"
chmod 0644 -- "$work/pelican-install.php" "$work/release.json"
printf 'Checking Pelican at %s as %s…\n' "$panel" "$panel_user"
"${runner[@]}" "$php_binary" "$work/pelican-install.php" preflight "$panel" "${fields[1]}" \
    || die 'Pelican checks failed. No plugin package has been installed.'
fetch "$package_url" "$work/wingman-ai.zip" || die 'Could not download the plugin package.'
actual_sha="$(php -r 'echo hash_file("sha256",$argv[1]);' "$work/wingman-ai.zip")"
[[ "$actual_sha" == "${fields[3]}" ]] || die 'The ZIP SHA-256 does not match the release manifest. Nothing was installed.'
chmod 0644 -- "$work/wingman-ai.zip"
"${runner[@]}" "$php_binary" "$work/pelican-install.php" install "$panel" "$work/wingman-ai.zip" "$work/release.json" "$force" \
    || die 'Installation failed; see the rollback or recovery details above.'
printf '\nOpen Pelican → Admin → Plugins → Wingman AI to configure your AI service.\n'
printf 'Automatic startup monitoring uses your existing Pelican queue worker.\n'
printf 'Updates: run this command again. Release notes: %s\n' "${fields[4]}"
