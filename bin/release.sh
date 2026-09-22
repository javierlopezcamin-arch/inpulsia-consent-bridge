#!/usr/bin/env bash
# Publica una nueva versión del plugin.
#
# Uso:  bin/release.sh 2.7.1
#
# Antes de ejecutarlo:
#   1. Haz tus cambios y añade la entrada "2.7.1 — Título" al CHANGELOG de README.txt.
#   2. Commitea esos cambios.
#
# El script actualiza la versión (cabecera, ICB_VERSION y README), valida PHP, crea el
# commit "Release v2.7.1", el tag v2.7.1 y hace push. GitHub Actions publica la release
# y las webs la verán en Plugins → "Buscar actualización".
set -euo pipefail

V="${1:-}"
if [[ ! "$V" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Uso: bin/release.sh X.Y.Z" >&2
  exit 1
fi

cd "$(dirname "$0")/.."

if [ -n "$(git status --porcelain)" ]; then
  echo "Hay cambios sin commitear. Commitéalos antes de publicar." >&2
  exit 1
fi
if git rev-parse "v$V" >/dev/null 2>&1; then
  echo "El tag v$V ya existe." >&2
  exit 1
fi
if ! grep -q "^$V " README.txt; then
  echo "Falta la entrada '$V — ...' en el CHANGELOG de README.txt." >&2
  exit 1
fi

sed -i.bak -E "s/^( \* Version:[[:space:]]+).*/\1$V/" inpulsia-consent-bridge.php
sed -i.bak -E "s/define\( 'ICB_VERSION', '[^']+' \)/define( 'ICB_VERSION', '$V' )/" inpulsia-consent-bridge.php
sed -i.bak -E "s/^Versión: .*/Versión: $V/" README.txt
rm -f inpulsia-consent-bridge.php.bak README.txt.bak

find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l >/dev/null

git add inpulsia-consent-bridge.php README.txt
git commit -m "Release v$V"
git tag -a "v$V" -m "v$V"
git push origin HEAD
git push origin "v$V"

echo "✓ v$V publicada. GitHub Actions creará la release en 1-2 minutos."
