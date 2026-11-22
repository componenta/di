#!/usr/bin/env bash
set -euo pipefail

message=${1-}
first_line=${message%%$'\n'*}
tag=${first_line#Release }
version='v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)'
prerelease='[-.]((alpha|beta|rc|a|b)([.-]?[0-9]+([.-][0-9]+)*)?([.-]?dev)?|dev)'

if [[ "$first_line" == "Release $tag" && "$tag" =~ ^$version$ ]]; then
  printf '%s false\n' "$tag"
elif [[ "$first_line" == "Release $tag" && "$tag" == v* && "${tag,,}" =~ ^$version$prerelease$ ]]; then
  printf '%s true\n' "$tag"
else
  printf 'Invalid release subject: %s\n' "$first_line" >&2
  exit 1
fi
