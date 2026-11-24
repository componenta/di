#!/usr/bin/env bash
set -euo pipefail

runtime_workspace="$(mktemp -d "$RUNNER_TEMP/di-native-php.XXXXXX")"
trap 'rm -rf "$runtime_workspace"' EXIT
cd "$runtime_workspace"

curl --fail --location --retry 3 \
  https://www.php.net/distributions/php-8.5.13.tar.xz \
  --output php-8.5.13.tar.xz
printf '%s  %s\n' \
  607602acaf0c3061818e9916ec44a058e8b3d43959a83862b9cd3ec7bf5b785f \
  php-8.5.13.tar.xz | sha256sum --check -
tar -xJf php-8.5.13.tar.xz

cd php-8.5.13
./configure --disable-all --enable-cli --disable-cgi --disable-phpdbg --without-pear
make -j2
native_php="$runtime_workspace/php-8.5.13/sapi/cli/php"
"$native_php" -n -r 'printf("PHP %s\n", PHP_VERSION); exit(PHP_VERSION === "8.5.13" ? 0 : 1);'
"$native_php" -n "$GITHUB_WORKSPACE/tests/php85-platform.php"
