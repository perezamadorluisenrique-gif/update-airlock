#!/usr/bin/env bash
# wordpress.org gate for a plugin folder (source or built copy). Recreates wporg-gate.sh.
# Usage: gate.sh <plugin-dir> <prefix> [release-zip]
#   env CONTRIBUTOR  expected readme Contributors (default lenrique5454)
#   env RISKY_CALLS  optional regex alternation of functions the plugin must never call,
#                    e.g. "eval|base64_decode|gzinflate|unserialize|create_function|assert"
G="$(cd "$(dirname "$0")" && pwd)"
D="$1"; P="$2"; Z="${3:-}"; FAIL=0; WARN=0
CONTRIB="${CONTRIBUTOR:-lenrique5454}"
f(){ echo "FAIL: $*"; FAIL=$((FAIL+1)); }
w(){ echo "WARN: $*"; WARN=$((WARN+1)); }
if [ -z "$D" ] || [ -z "$P" ]; then echo "usage: gate.sh <plugin-dir> <prefix> [zip]"; exit 2; fi
cd "$D" || exit 2
SLUG="$(basename "$PWD")"

for x in $(find . -name "*.php"); do
  [ "$(basename "$x")" = index.php ] && continue
  if [ "$x" = ./uninstall.php ]; then
    grep -q "WP_UNINSTALL_PLUGIN" "$x" || f "no uninstall guard $x"
  else
    grep -q "defined( 'ABSPATH' )" "$x" || f "no ABSPATH guard $x"
  fi
  sed -n 1,25p "$x" | grep -q "declare(strict_types=1)" || w "no strict_types $x"
done
for d in $(find . -type d); do [ -f "$d/index.php" ] || f "no index.php in $d"; done

grep -rnIE "localhost|127\.0\.0\.1|\.local\b" . && f "localhost-like string (reviewers grep for it; comments count)"
grep -rnI "<script\|<style" --include=*.php . && f "<script>/<style> text in PHP (even inside a regex: write <\\s*script)"
grep -rnI "load_plugin_textdomain" . && f "load_plugin_textdomain"
find . \( -name "*.po" -o -name "*.mo" -o -name "*.pot" -o -name "*.l10n.php" -o -type d -name languages \) | grep . && f "translation files"
if grep -q "^ \* Domain Path:" ./*.php 2>/dev/null && [ ! -d languages ]; then w "Domain Path header without a languages/ folder (Plugin Check warns)"; fi
find . -name ".*" ! -name ".htaccess" ! -name "." | grep . && f "dotfiles"
find . \( -iname "composer.*" -o -iname "package*.json" -o -iname "phpunit*" -o -iname "phpcs*" -o -type d -name tests -o -type d -name node_modules -o -type d -name vendor -o -type d -name bin -o -name "*.zip" -o -name "*.log" -o -iname "*fixture*" \) | grep . && f "dev files"
grep -rnE '\{\$[a-z]' --include=*.php . | grep -iE "select|insert|update|delete|drop|from" && f "interpolated SQL"
grep -rnIE "(function|class)_exists\( *'$P" . && f "exists-guard around own code"
grep -rnI "remove_action\|remove_all_actions" --include=*.php . && f "remove_action (core UI is not ours)"
grep -rnIE 'echo \$[a-z_]+;' --include=*.php . && w "echo of a raw variable"
grep -rnIE "\b(only|best|first|unlike|no other|fastest)\b" readme.txt | grep -viE "only when|only the|only mentions|only requests|only %s|first release|first run" && w "possible comparative claim in readme"
grep -rniE "compliant|compliance|prevents hacks|blocks malware|unhackable|100% secure" readme.txt && f "forbidden claim"

for c in $(find . -name "*.css"); do
  grep -E "\{" "$c" | grep -v "$P" && f "unprefixed CSS selector in $c"
  grep -q ":root" "$c" && f ":root in $c"
done

if [ -n "${RISKY_CALLS:-}" ]; then
  grep -rnE "(^|[^'\"a-z_>:])(${RISKY_CALLS})\s*\(" --include=*.php . && f "plugin calls a function it should only list as a string"
fi

grep -q "^Contributors: $CONTRIB" readme.txt || f "readme Contributors is not $CONTRIB"
V=$(grep -m1 -h "^ \* Version:" ./*.php | sed 's/.*Version: *//' | tr -d '\r ')
grep -q "^Stable tag: $V" readme.txt || f "Stable tag != Version ($V)"
grep -q "^Tested up to:" readme.txt || f "no Tested up to"
SD=$(awk '/^License URI/{getline; getline; print; exit}' readme.txt)
[ ${#SD} -le 150 ] || f "short description ${#SD} > 150"
for s in Description Installation "Frequently Asked Questions" Changelog; do
  grep -q "== $s ==" readme.txt || f "missing == $s =="
done
if grep -rlqE "wp_remote_|wp_safe_remote_|download_url|curl_init|Requests::" --include=*.php .; then
  grep -q "== External services ==" readme.txt || f "outbound requests but no == External services =="
fi

if [ -n "$Z" ]; then python -I "$G/zipcheck.py" "$Z" "$SLUG" || FAIL=$((FAIL+1)); fi
echo "GATE: $FAIL FAIL, $WARN WARN"
exit $FAIL
