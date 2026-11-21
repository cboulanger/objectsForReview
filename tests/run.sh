#!/bin/bash
# Run the plugin's test suites.
#
#   tests/run.sh                 all suites
#   tests/run.sh unit            all unit suites
#   tests/run.sh e2e/api unit/php   the named suites
#   tests/run.sh --list          list the suites
#
# A suite is any directory tests/<layer>/<name>/ with an executable run.sh;
# new suites (e.g. tests/e2e/ui) are picked up without changing this script.
# Arguments after "--" are passed to every suite's run.sh.
set -uo pipefail

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

suites=()
for script in "$TESTS_DIR"/*/*/run.sh; do
	[ -x "$script" ] || continue
	suite="${script#"$TESTS_DIR"/}"
	suites+=("${suite%/run.sh}")
done

selectors=()
passthrough=()
while [ $# -gt 0 ]; do
	case "$1" in
		--list) printf '%s\n' "${suites[@]}"; exit 0 ;;
		-h|--help) sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
		--) shift; passthrough=("$@"); break ;;
		*) selectors+=("${1%/}") ;;
	esac
	shift
done

selected=()
for suite in "${suites[@]}"; do
	if [ ${#selectors[@]} -eq 0 ]; then
		selected+=("$suite")
		continue
	fi
	for selector in "${selectors[@]}"; do
		if [ "$suite" = "$selector" ] || [ "${suite%%/*}" = "$selector" ]; then
			selected+=("$suite")
			break
		fi
	done
done

if [ ${#selected[@]} -eq 0 ]; then
	echo "No suite matches: ${selectors[*]} (available: ${suites[*]})" >&2
	exit 2
fi

failed=()
for suite in "${selected[@]}"; do
	printf '\n\033[1m=== %s ===\033[0m\n' "$suite"
	if "$TESTS_DIR/$suite/run.sh" "${passthrough[@]+"${passthrough[@]}"}"; then
		printf '\033[32m--- %s: passed\033[0m\n' "$suite"
	else
		printf '\033[31m--- %s: FAILED\033[0m\n' "$suite"
		failed+=("$suite")
	fi
done

echo
if [ ${#failed[@]} -gt 0 ]; then
	printf '\033[31mFailed: %s\033[0m\n' "${failed[*]}"
	exit 1
fi
printf '\033[32mAll passed: %s\033[0m\n' "${selected[*]}"
