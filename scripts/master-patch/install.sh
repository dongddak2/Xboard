#!/usr/bin/env bash
set -Eeuo pipefail
[[ $EUID == 0 ]] || exit 1
BASE=/root/xboard-test
DIR=$(cd -- "$(dirname -- "$0")" && pwd)
[[ -d "$BASE" && ! -L "$BASE" ]]
BACKUP=/root/mandu-master-before-$(date +%Y%m%d-%H%M%S)-$$
mkdir -m 700 "$BACKUP"
restore() {
  trap - ERR INT TERM
  if [[ -d "$BACKUP/app" ]]; then cp -a "$BACKUP/app/." "$BASE/app/"; fi
  if [[ -f "$BACKUP/MasterAccess.php" ]]; then cp -p "$BACKUP/MasterAccess.php" "$BASE/app/Services/MasterAccess.php";
  elif [[ -f "$BACKUP/helper-was-absent" && -f "$BASE/app/Services/MasterAccess.php" ]]; then mv "$BASE/app/Services/MasterAccess.php" "$BACKUP/MasterAccess.failed.php"; fi
  docker restart xboard-test-web-1 xboard-test-horizon-1 >/dev/null || true
  echo "마스터 정책 적용 중단·원본 복구: $BACKUP"
  exit 1
}
trap restore ERR INT TERM
python3 "$DIR/patch.py" "$BACKUP"
for file in app/Services/MasterAccess.php app/Services/PlanService.php app/Services/ServerService.php app/Services/UserService.php app/Services/ServerQuotaService.php app/Http/Controllers/V2/Admin/UserController.php app/Http/Controllers/V2/Admin/PlanController.php app/Http/Controllers/V1/Vpn/VpnEntitlementController.php app/Http/Controllers/V1/User/ServerCatalogController.php; do
  docker exec -w /www xboard-test-web-1 php -l "$file"
done
docker exec -w /www xboard-test-web-1 php -r 'require "vendor/autoload.php"; if (!class_exists(\App\Services\MasterAccess::class)) exit(1);'
docker restart xboard-test-web-1 xboard-test-horizon-1 >/dev/null
trap - ERR INT TERM
echo "마스터 정책 코드 적용 완료. 회원 자동 배정 없음. 원본: $BACKUP"
