#!/usr/bin/env bash
# Deploys the `cpanel` branch from outside the host (the `cPanel branch` workflow runs it after publishing):
# through cPanel's UAPI it pulls the branch into the account's Git Version Control repository, runs the
# deployment (.cpanel.yml → deploy.sh) and waits for its result. Exits non-zero when any step fails.
#
# Settings (environment variables):
#   CPANEL_HOST        host name of the cPanel server (UAPI on port 2083)  required
#   CPANEL_USER        cPanel account name                                  required
#   CPANEL_TOKEN       cPanel API token (cPanel → Manage API Tokens)        required
#   CPANEL_REPOSITORY  repository path on the account                       default: /home/$CPANEL_USER/repositories/sic
#   EXPECTED_COMMIT    commit the pull must reach (the published cpanel commit); unchecked when empty
#   DEPLOY_TIMEOUT     seconds to wait for the deployment                   default: 900
set -euo pipefail

: "${CPANEL_HOST:?CPANEL_HOST is not set}" "${CPANEL_USER:?CPANEL_USER is not set}" "${CPANEL_TOKEN:?CPANEL_TOKEN is not set}"
REPOSITORY="${CPANEL_REPOSITORY:-/home/$CPANEL_USER/repositories/sic}"
TIMEOUT="${DEPLOY_TIMEOUT:-900}"

fail() {
    echo "cPanel remote deploy: $*" >&2
    exit 1
}

# uapi MODULE/FUNCTION [name=value …]: prints the `data` of a successful call, fails with cPanel's errors otherwise.
uapi() {
    local endpoint=$1 response
    shift
    local args=()
    for pair in "$@"; do
        args+=(--data-urlencode "$pair")
    done
    response="$(curl --silent --show-error --fail-with-body --max-time 120 --get \
        --header "Authorization: cpanel $CPANEL_USER:$CPANEL_TOKEN" \
        "${args[@]}" "https://$CPANEL_HOST:2083/execute/$endpoint")" ||
        fail "$endpoint: HTTP request failed: $response"
    jq -e '.status == 1' >/dev/null <<<"$response" ||
        fail "$endpoint: $(jq -r '(.errors // ["unknown error"]) | join("; ")' <<<"$response")"
    jq -c '.data' <<<"$response"
}

print_log() {
    uapi Fileman/get_file_content "dir=$(dirname "$1")" "file=$(basename "$1")" | jq -r '.content' || true
}

# Pull. cPanel pulls with --ff-only: the workflow keeps the cpanel branch fast-forward for this.
head="$(uapi VersionControl/update "repository_root=$REPOSITORY" branch=cpanel | jq -r '.last_update.identifier')"
echo "cPanel remote deploy: $REPOSITORY is at $head"
if [ -n "${EXPECTED_COMMIT:-}" ] && [ "$head" != "$EXPECTED_COMMIT" ]; then
    fail "the pull stopped at $head instead of $EXPECTED_COMMIT. If the checkout on the host has diverged, reset it once in the Terminal: cd $REPOSITORY && git fetch origin && git reset --hard origin/cpanel"
fi

deployment="$(uapi VersionControlDeployment/create "repository_root=$REPOSITORY")"
deploy_id="$(jq -r '.deploy_id' <<<"$deployment")"
log_path="$(jq -r '.log_path' <<<"$deployment")"
echo "cPanel remote deploy: deployment $deploy_id queued (log: $log_path)"

deadline=$((SECONDS + TIMEOUT))
while :; do
    sleep 5
    timestamps="$(uapi VersionControlDeployment/retrieve |
        jq -c --arg id "$deploy_id" '.[] | select((.deploy_id | tostring) == $id) | .timestamps')"
    if jq -e '.succeeded' >/dev/null <<<"${timestamps:-null}"; then
        print_log "$log_path"
        echo "cPanel remote deploy: done."
        exit 0
    fi
    if jq -e '.failed or .canceled' >/dev/null <<<"${timestamps:-null}"; then
        print_log "$log_path"
        fail "deployment $deploy_id failed (log above)."
    fi
    [ "$SECONDS" -lt "$deadline" ] || fail "deployment $deploy_id did not finish within ${TIMEOUT}s (log: $log_path)."
done
