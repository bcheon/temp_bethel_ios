#!/usr/bin/env bash
# bin/test.sh — single entry point for local + CI test runs.
# Targets are dispatched by the first arg; everything is portable bash 3.2+.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_DIR="$ROOT/wordpress-plugin/bkc-push"
IOS_DIR="$ROOT/ios/BKC"

# ---- helpers ----------------------------------------------------------------

log()  { printf '\033[1;34m[test.sh]\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m[test.sh]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[test.sh]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[test.sh]\033[0m %s\n' "$*" >&2; exit 1; }

is_mac() { [ "$(uname -s)" = "Darwin" ]; }
have()   { command -v "$1" >/dev/null 2>&1; }

# ---- targets ----------------------------------------------------------------

install_tools() {
  log "Installing tools…"

  if is_mac; then
    have brew || die "Homebrew is required on macOS. Install from https://brew.sh and re-run."
    have php       || brew install php
    have composer  || brew install composer
    have xcodegen  || brew install xcodegen
    have act       || brew install act
  else
    have php       || die "Install PHP 8.1+ via your package manager."
    have composer  || die "Install Composer via https://getcomposer.org/download/"
    have act       || warn "act not installed — \`make ci-local\` will not work."
  fi

  log "composer install for WP plugin…"
  ( cd "$WP_DIR" && composer install --prefer-dist --no-progress --no-interaction )

  ok "Tooling ready: $(have php && php --version | head -1) | $(have composer && composer --version) | $(have xcodegen && echo xcodegen=$(xcodegen --version 2>&1 | tr -d '\n'))"
}

test_wp() {
  [ -x "$WP_DIR/vendor/bin/phpunit" ] || ( cd "$WP_DIR" && composer install --prefer-dist --no-progress --no-interaction )
  log "Running PHPUnit…"
  ( cd "$WP_DIR" && vendor/bin/phpunit )
  ok "PHPUnit suite passed."
}

test_ios() {
  if ! is_mac; then
    warn "iOS tests require macOS + Xcode. Skipping."
    return 0
  fi
  have xcodegen || die "xcodegen missing. Run: make install"
  log "Generating Xcode project…"
  ( cd "$IOS_DIR" && xcodegen generate )

  log "Running iOS unit tests…"
  set -o pipefail
  ( cd "$IOS_DIR" && xcodebuild test \
      -project BKC.xcodeproj \
      -scheme BKC \
      -destination "platform=iOS Simulator,name=iPhone 17,OS=latest" \
      -resultBundlePath build/Test.xcresult \
      CODE_SIGNING_ALLOWED=NO )
  ok "iOS suite passed."
}

test_all() {
  test_wp
  if is_mac; then
    test_ios
  else
    warn "Skipping iOS tests (not on macOS)."
  fi
  ok "All test suites passed."
}

ci_local() {
  have act    || die "act missing. Run: make install"
  have docker || die "Docker is required for act. Install Docker Desktop and ensure 'docker info' works."
  docker info >/dev/null 2>&1 || die "Docker daemon not running."

  log "Running .github/workflows/ci.yml job 'wp-test' (PHP 8.2 only) via act…"
  # Use --container-architecture for Apple Silicon compatibility.
  local ARCH_FLAG=""
  if [ "$(uname -m)" = "arm64" ]; then
    ARCH_FLAG="--container-architecture linux/amd64"
  fi
  ( cd "$ROOT" && act -W .github/workflows/ci.yml -j wp-test --matrix php:8.2 $ARCH_FLAG )
  ok "Local CI run finished."
}

# ---- dispatch ---------------------------------------------------------------

cmd="${1:-help}"
case "$cmd" in
  install)   install_tools ;;
  test-wp)   test_wp ;;
  test-ios)  test_ios ;;
  test-all)  test_all ;;
  ci-local)  ci_local ;;
  help|*)
    sed -n '2,4p' "$0" | sed 's/^# //'
    echo
    echo "Usage: $0 {install|test-wp|test-ios|test-all|ci-local}"
    ;;
esac
