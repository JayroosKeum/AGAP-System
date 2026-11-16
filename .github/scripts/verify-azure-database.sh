#!/usr/bin/env bash
set -euo pipefail

app_name=AGAP
resource_group=$(az webapp list --query "[?name=='$app_name'].resourceGroup | [0]" -o tsv)
test -n "$resource_group"
scm_host=$(az webapp show -g "$resource_group" -n "$app_name" --query "enabledHostNames[?contains(@, '.scm.')]|[0]" -o tsv)
app_host=$(az webapp show -g "$resource_group" -n "$app_name" --query defaultHostName -o tsv)
instance=$(az webapp list-instances -g "$resource_group" -n "$app_name" --query '[0].name' -o tsv)
test -n "$scm_host" && test -n "$app_host" && test -n "$instance"

run_id="$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"
payload=$(tar -czf - .github/scripts/apply-release-migrations.php | base64 -w0)
remote_script=$(cat <<'REMOTE'
set -eu
workdir=$(mktemp -d)
trap 'rm -f -- "$workdir"/apply-release-migrations.php; rmdir "$workdir"' EXIT
status_path="/home/LogFiles/agap-schema-$run_id.status"
log_path="/home/LogFiles/agap-schema-$run_id.log"
if printf '%s' "$payload" | base64 -d | tar -xz --strip-components=2 -C "$workdir" \
    && php "$workdir/apply-release-migrations.php" --check-only > "$log_path" 2>&1; then
    result=ok
else
    result=failed
fi
printf '%s\n' "$result" > "$status_path.tmp"
mv "$status_path.tmp" "$status_path"
REMOTE
)

az webapp exec -g "$resource_group" -n "$app_name" --instance "$instance" \
  --mode execute --shell /bin/bash --shell-command "run_id='$run_id'; payload='$payload'; $remote_script" -o none

token=$(az account get-access-token --resource https://management.azure.com/ --query accessToken -o tsv)
echo "::add-mask::$token"
status_url="https://$scm_host/api/vfs/LogFiles/agap-schema-$run_id.status"
for attempt in {1..36}; do
    status=$(curl --silent --show-error --fail --max-time 8 --header "Authorization: Bearer $token" "$status_url" 2>/dev/null || true)
    case "$status" in
        ok)
            printf 'url=https://%s\n' "$app_host" >> "$GITHUB_OUTPUT"
            echo 'Production database schema verified from the App Service container.'
            exit 0
            ;;
        failed)
            curl --silent --fail --max-time 8 --header "Authorization: Bearer $token" "${status_url%.status}.log" || true
            echo '::error::The production database did not pass release checks. The application release was held.'
            exit 1
            ;;
    esac
    sleep 5
done
echo '::error::Could not verify the production database through Kudu. The application release was held.'
exit 1
