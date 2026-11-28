#!/usr/bin/env bash
set -euo pipefail

# Build a web-root artifact from an explicit runtime allowlist. Tests,
# documentation drafts, migrations, local credentials, Git metadata, and
# temporary batch state are intentionally never copied.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
HEAD_SHORT="$(git -C "$ROOT" rev-parse --short=12 HEAD)"
OUTPUT="${1:-/tmp/checador-v2-api-release-${HEAD_SHORT}-working-tree.tar.gz}"
OUTPUT_DIR="$(dirname "$OUTPUT")"
mkdir -p "$OUTPUT_DIR"
OUTPUT_DIR="$(cd "$OUTPUT_DIR" && pwd -P)"
OUTPUT="$OUTPUT_DIR/$(basename "$OUTPUT")"

case "$OUTPUT" in
    "$ROOT"/*) echo "Refusing to write artifact inside repository" >&2; exit 2 ;;
esac

STAGE="$(mktemp -d "${TMPDIR:-/tmp}/checador-api-release.XXXXXXXX")"
trap 'rm -rf "$STAGE"' EXIT

for path in index.php .htaccess modelo scripts/JourneyBatchProcessor.php \
    scripts/JourneyEnvironmentProfile.php scripts/journey_batch_runtime.php \
    scripts/journey_processor.php; do
    test -e "$ROOT/$path" || { echo "Required runtime path missing: $path" >&2; exit 2; }
done

cp -p "$ROOT/index.php" "$ROOT/.htaccess" "$STAGE/"
cp -a "$ROOT/modelo" "$STAGE/modelo"
mkdir -p "$STAGE/scripts"
for path in JourneyBatchProcessor.php JourneyEnvironmentProfile.php \
    journey_batch_runtime.php journey_processor.php; do
    cp -p "$ROOT/scripts/$path" "$STAGE/scripts/$path"
done

# Refuse symlinks and sensitive local material in the staged runtime tree.
if find "$STAGE" -type l -print -quit | grep -q .; then
    echo "Refusing symlink in release artifact" >&2
    exit 2
fi
if find "$STAGE" \( -name '.env*' -o -name '.qa-local-fixtures.json' \
    -o -name 'tests' -o -name '.git' -o -name '.aws' -o -name '.agents' \
    -o -name '.codex' -o -name '*checkpoint*' -o -name '*.sock' \
    -o -name '*.log' \) -print -quit | grep -q .; then
    echo "Forbidden test/private/runtime state in release artifact" >&2
    exit 2
fi

(
    cd "$STAGE"
    find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS
)

LIST="$(tar -C "$STAGE" -cf - . | tar -tf -)"
if printf '%s\n' "$LIST" | grep -Eiq '(^|/)(tests|\.git|\.aws|\.agents|\.codex)(/|$)|(^|/)\.env([^/]*)?$|\.sock$|\.log$|checkpoint'; then
    echo "Forbidden path detected in release listing" >&2
    exit 2
fi
printf '%s\n' "$LIST" | grep -q '^\./index.php$'
printf '%s\n' "$LIST" | grep -q '^\./modelo/'
printf '%s\n' "$LIST" | grep -q '^\./scripts/journey_processor.php$'
printf '%s\n' "$LIST" | grep -q '^\./scripts/journey_batch_runtime.php$'

tar --sort=name --mtime='UTC 2026-01-01' --owner=0 --group=0 --numeric-owner \
    -C "$STAGE" -cf - . | gzip -n > "$OUTPUT"
gzip -t "$OUTPUT"
tar -tzf "$OUTPUT" > "${OUTPUT}.contents.txt"
if grep -Eiq '(^|/)(tests|\.git|\.aws|\.agents|\.codex)(/|$)|(^|/)\.env([^/]*)?$|\.sock$|\.log$|checkpoint' "${OUTPUT}.contents.txt"; then
    rm -f "$OUTPUT" "${OUTPUT}.contents.txt"
    echo "Forbidden path in final artifact" >&2
    exit 2
fi

printf 'ARTIFACT=%s\n' "$OUTPUT"
printf 'FILES=%s\n' "$(wc -l < "${OUTPUT}.contents.txt" | tr -d ' ')"
sha256sum "$OUTPUT"
