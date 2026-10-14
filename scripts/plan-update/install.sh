#!/usr/bin/env bash
set -Eeuo pipefail
[[ $EUID == 0 ]] || exit 1
DIR=$(cd -- "$(dirname -- "$0")" && pwd)
DEST=/root/xboard-test/storage/app/mandu-plan-update
[[ -d /root/xboard-test && ! -L /root/xboard-test ]]
mkdir -p "$DEST"
cp "$DIR/PlanRules.php" "$DIR/expected-prices.json" "$DIR/apply.php" "$DIR/run.php" "$DEST/"
docker exec -w /www xboard-test-web-1 php -l storage/app/mandu-plan-update/apply.php
docker exec -w /www xboard-test-web-1 php storage/app/mandu-plan-update/run.php
if [[ "${1:-}" != --apply ]]; then echo '조회 완료. 실제 적용: bash install.sh --apply'; exit 0; fi
docker exec -e MANDU_PLAN_APPLY=1 -w /www xboard-test-web-1 php storage/app/mandu-plan-update/run.php
