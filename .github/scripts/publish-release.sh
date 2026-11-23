#!/usr/bin/env bash
set -euo pipefail

release="$(bash "$(dirname "${BASH_SOURCE[0]}")/release-tag.sh" "$COMMIT_MESSAGE")"
read -r tag prerelease <<< "$release"
tag_ref="refs/tags/$tag"

remote_tag_target() {
  local remote target ref direct='' peeled=''
  remote="$(git ls-remote --tags origin "$tag_ref" "$tag_ref^{}")" || return
  while IFS=$'\t' read -r target ref; do
    if [[ "$ref" == "$tag_ref" ]]; then
      direct="$target"
    elif [[ "$ref" == "$tag_ref^{}" ]]; then
      peeled="$target"
    fi
  done <<< "$remote"
  printf '%s\n' "${peeled:-$direct}"
}

require_tested_target() {
  local target=$1
  if [[ "$target" != "$GITHUB_SHA" ]]; then
    printf 'Release tag %s points to %s, expected tested commit %s.\n' \
      "$tag" "${target:-no commit}" "$GITHUB_SHA" >&2
    return 1
  fi
}

verify_remote_tag() {
  local target
  target="$(remote_tag_target)" || return
  require_tested_target "$target"
}

release_status() {
  gh release view "$tag" --json isDraft,isPrerelease --jq '[.isDraft, .isPrerelease] | join(" ")' 2>/dev/null
}

require_published_release() {
  case "$1" in
    "false $prerelease") return 0 ;;
    'true '*) printf 'Release %s is still a draft.\n' "$tag" >&2 ;;
    'false true'|'false false')
      printf 'Release %s has prerelease status %s, expected %s.\n' "$tag" "${1#false }" "$prerelease" >&2
      ;;
    *) printf 'Cannot verify publication status for release %s.\n' "$tag" >&2 ;;
  esac
  return 1
}

tag_target="$(remote_tag_target)"
if [[ -n "$tag_target" ]]; then
  require_tested_target "$tag_target"
fi

existing_release=false
if status="$(release_status)"; then
  require_published_release "$status"
  existing_release=true
fi

if [[ -z "$tag_target" ]]; then
  # A concurrent publisher may create the tag first. A non-forcing push
  # cannot replace its tag, and the remote target is verified below.
  git push --no-follow-tags origin "$GITHUB_SHA:$tag_ref" || :
fi

verify_remote_tag
if [[ "$existing_release" == true ]]; then
  printf 'Release %s already exists.\n' "$tag"
  exit 0
fi

release_flags=()
if [[ "$prerelease" == true ]]; then
  release_flags+=(--prerelease)
fi

if ! gh release create "$tag" \
  "${release_flags[@]}" \
  --verify-tag \
  --target "$GITHUB_SHA" \
  --title "$tag" \
  --generate-notes
then
  # A parallel publish of the same tag can complete after the initial lookup.
  status="$(release_status)"
  require_published_release "$status"
  printf 'Release %s already exists.\n' "$tag"
fi
verify_remote_tag
