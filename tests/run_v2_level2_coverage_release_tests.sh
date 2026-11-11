#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASELINE_FILE="$ROOT_DIR/deployment/preprod/release_telemetry_20261027/Level2ServerService.php"
PATCH_FILE="$ROOT_DIR/deployment/preprod/2026-10-27_level2_coverage.patch"
EXPECTED_BASELINE_SHA256='76283fcfdbb784e2683274c91b93af12a2c2522e8f66e19ac678daa83c02304b'
EXPECTED_PATCHED_SHA256='2762093a56acec2cad4a1830df51ffe2aa14c51bac47b55338b8eff0dd3457c5'
TEMP_DIR="$(mktemp -d)"

cleanup() {
    rm -rf -- "$TEMP_DIR"
}
trap cleanup EXIT INT TERM

for command_name in sha256sum git php; do
    command -v "$command_name" >/dev/null 2>&1 || { echo "BLOCKED: missing required command $command_name"; exit 2; }
done

ACTUAL_BASELINE_SHA256="$(sha256sum "$BASELINE_FILE" | awk '{print $1}')"
if [[ "$ACTUAL_BASELINE_SHA256" != "$EXPECTED_BASELINE_SHA256" ]]; then
    echo "BLOCKED: telemetry baseline mismatch; expected $EXPECTED_BASELINE_SHA256, got $ACTUAL_BASELINE_SHA256"
    exit 2
fi

mkdir -p "$TEMP_DIR/overlay/modelo/v2"
cp -- "$BASELINE_FILE" "$TEMP_DIR/overlay/modelo/v2/Level2ServerService.php"
ln -s "$ROOT_DIR/modelo/BD.php" "$TEMP_DIR/overlay/modelo/BD.php"
ln -s "$ROOT_DIR/modelo/v2/Portal3JwtAuthHelper.php" "$TEMP_DIR/overlay/modelo/v2/Portal3JwtAuthHelper.php"
git -C "$TEMP_DIR/overlay" apply -p1 "$PATCH_FILE"

ACTUAL_PATCHED_SHA256="$(sha256sum "$TEMP_DIR/overlay/modelo/v2/Level2ServerService.php" | awk '{print $1}')"
if [[ "$ACTUAL_PATCHED_SHA256" != "$EXPECTED_PATCHED_SHA256" ]]; then
    echo "BLOCKED: coverage output mismatch; expected $EXPECTED_PATCHED_SHA256, got $ACTUAL_PATCHED_SHA256"
    exit 2
fi
php -l "$TEMP_DIR/overlay/modelo/v2/Level2ServerService.php"
echo "TELEMETRY_BASELINE_SHA256: $ACTUAL_BASELINE_SHA256"
echo "COVERAGE_OUTPUT_SHA256: $ACTUAL_PATCHED_SHA256"
echo 'COVERAGE_PATCH_APPLICATION: PASS'

export CHECADOR_LEVEL2_SERVICE_FILE="$TEMP_DIR/overlay/modelo/v2/Level2ServerService.php"
"$ROOT_DIR/tests/run_v2_level2_append_only_tests.sh"
