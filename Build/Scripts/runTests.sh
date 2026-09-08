#!/usr/bin/env bash

#
# EXT:ai_label test runner.
#
# Runs functional tests, static analysis and the coding standards check inside a
# Docker container, using the same PHP images as the TYPO3 Core CI.
#
# Usage:
#   Build/Scripts/runTests.sh                             # Functional tests on MySQL
#   Build/Scripts/runTests.sh -s functional -d sqlite     # Functional tests on SQLite
#   Build/Scripts/runTests.sh -s functional -d mariadb    # Functional tests on MariaDB
#   Build/Scripts/runTests.sh -s phpstan                  # Static analysis, v14 dependencies
#   Build/Scripts/runTests.sh -s phpstan13                # Static analysis, v13 dependencies
#   Build/Scripts/runTests.sh -s cgl                      # Coding standards check
#   Build/Scripts/runTests.sh -s lint                     # php -l over Classes and Tests
#   Build/Scripts/runTests.sh -p 8.5                      # Use PHP 8.5
#   Build/Scripts/runTests.sh -x                          # Enable Xdebug
#   Build/Scripts/runTests.sh -- --filter tickingReviewed  # Pass arguments to phpunit
#
# What is installed in .Build has to fit the PHP version picked here: composer
# resolves phpunit against the PHP that installed it, and a phpunit built for 8.4
# refuses to start on 8.2. Remove .Build to let this script install again.
#
# phpstan13 is the exception to "no local setup needed": it analyses whatever is in
# .Build, so the v13 dependency set has to be installed first (see the -h output).
# Against a v14 .Build it reports unrelated errors, because the v13 baseline entries
# no longer match.
#

set -e
set -u
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# Defaults
PHP_VERSION="8.4"
TEST_SUITE="functional"
DBMS="mysql"
PHPUNIT_ARGS=()
XDEBUG_ARGS=()

# MySQL is the default because CI runs it and because the JSON fixtures are
# written the way MySQL returns a json column, with a space after every colon.
# MariaDB and SQLite hand back the string as stored, so a number of assertions
# fail there for that reason alone. Both are still worth running: SQLite accepts
# a double-quoted unknown column as a string literal instead of erroring, so a
# query against a column that does not exist passes there and fails everywhere
# else, and only running one engine hides that in either direction.
MYSQL_IMAGE="mysql:8.0"
MARIADB_IMAGE="mariadb:10.11"
DB_CONTAINER="ai-label-test-db-$$"
DB_NETWORK="ai-label-test-net-$$"
DB_PASSWORD="funcp"

# Image base, matches TYPO3 Core CI images.
IMAGE_PREFIX="ghcr.io/typo3/core-testing-php"

# The tag streams of these images are per PHP version and NOT in lockstep: as of
# 2026-09-08 php82 is on 1.15.x, php83 on 1.16.x, php84 and php85 on 1.8.x. A
# single shared tag therefore resolves to a non-existent image for some versions,
# and that only fails where the image is not already cached locally. TYPO3 Core
# has the same lookup in its own runTests.sh (getPhpImageVersion).
getPhpImageVersion() {
    case ${1} in
        8.2) echo -n "1.15" ;;
        8.3) echo -n "1.16" ;;
        8.4) echo -n "1.8" ;;
        8.5) echo -n "1.8" ;;
        *)
            echo "Unsupported PHP version: ${1} (expected 8.2, 8.3, 8.4 or 8.5)" >&2
            exit 1
            ;;
    esac
}

usage() {
    cat <<EOF
Usage: $(basename "$0") [options] [-- phpunit-args]

Options:
    -s <suite>    Test suite: functional (default), phpstan, phpstan13, cgl, lint
    -p <version>  PHP version: 8.2, 8.3, 8.4 (default), 8.5
    -d <dbms>     Functional DBMS: mysql (default), mariadb, sqlite
    -x            Enable Xdebug
    -h            Show this help

Examples:
    $(basename "$0")                                Functional tests on MySQL
    $(basename "$0") -s functional -d sqlite        Functional tests on SQLite
    $(basename "$0") -s phpstan                     PHPStan against v14
    $(basename "$0") -- --filter tickingReviewed    Run a single test

phpstan13 needs the v13 dependency set in .Build:
    composer require typo3/cms-backend:^13.4 --dev -W
EOF
    exit "${1:-0}"
}

while getopts "s:p:d:xh" opt; do
    case ${opt} in
        s) TEST_SUITE="${OPTARG}" ;;
        p) PHP_VERSION="${OPTARG}" ;;
        d) DBMS="${OPTARG}" ;;
        x) XDEBUG_ARGS=(-e XDEBUG_MODE=debug -e XDEBUG_CONFIG=client_host=host.docker.internal) ;;
        h) usage 0 ;;
        *) usage 1 ;;
    esac
done
shift $((OPTIND - 1))
PHPUNIT_ARGS=("$@")

# Validated here rather than inside getPhpImageVersion: that runs in a command
# substitution, where its exit only ends the subshell.
case ${PHP_VERSION} in
    8.2 | 8.3 | 8.4 | 8.5) ;;
    *)
        echo "Unsupported PHP version: ${PHP_VERSION} (expected 8.2, 8.3, 8.4 or 8.5)" >&2
        exit 1
        ;;
esac

PHP_IMAGE="${IMAGE_PREFIX}$(echo "${PHP_VERSION}" | tr -d '.'):$(getPhpImageVersion "${PHP_VERSION}")"

# Ensure the dependencies exist (composer install)
if [ ! -d "${ROOT_DIR}/.Build/bin" ]; then
    echo "Running composer install..."
    if ! docker run --rm \
        -v "${ROOT_DIR}:/app" \
        -w /app \
        "${PHP_IMAGE}" \
        composer install --no-progress --no-interaction 2>&1; then
        echo "composer install failed" >&2
        exit 1
    fi
fi

# The functional tests render images, which needs ImageMagick. It ships with the
# core testing images, so nothing to install here, unlike in CI.
runFunctional() {
    docker run --rm \
        -v "${ROOT_DIR}:/app" \
        -w /app \
        "$@" \
        ${XDEBUG_ARGS[@]+"${XDEBUG_ARGS[@]}"} \
        "${PHP_IMAGE}" \
        php -d memory_limit=2G .Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml \
        ${PHPUNIT_ARGS[@]+"${PHPUNIT_ARGS[@]}"}
}

# Starts the database container and waits for it to accept connections. The
# admin client is named differently in the two images.
startDatabase() {
    local image="${1}"
    local pingCommand="${2}"
    docker network create "${DB_NETWORK}" >/dev/null
    docker run --rm --name "${DB_CONTAINER}" --network "${DB_NETWORK}" -d \
        -e MYSQL_ROOT_PASSWORD="${DB_PASSWORD}" \
        -e MARIADB_ROOT_PASSWORD="${DB_PASSWORD}" \
        "${image}" \
        --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci >/dev/null
    echo -n "Waiting for the database"
    local ready=0
    for _ in $(seq 1 60); do
        if docker exec "${DB_CONTAINER}" "${pingCommand}" ping -uroot -p"${DB_PASSWORD}" >/dev/null 2>&1; then
            ready=1
            break
        fi
        echo -n "."
        sleep 1
    done
    echo ""
    if [ "${ready}" -ne 1 ]; then
        echo "The database did not accept connections within 60 seconds." >&2
        exit 1
    fi
}

# Wrapped in a function called with "|| EXIT_CODE=$?" below: that suppresses errexit
# for the suite itself, so a failing test run still reaches the summary instead of
# aborting the script.
runSuite() {
case ${TEST_SUITE} in
    functional)
        case ${DBMS} in
            sqlite)
                echo "Running functional tests with PHP ${PHP_VERSION} (SQLite)..."
                runFunctional -e typo3DatabaseDriver=pdo_sqlite
                ;;
            mysql|mariadb)
                if [ "${DBMS}" = "mysql" ]; then
                    DB_IMAGE="${MYSQL_IMAGE}"
                    DB_PING="mysqladmin"
                else
                    DB_IMAGE="${MARIADB_IMAGE}"
                    DB_PING="mariadb-admin"
                fi
                echo "Running functional tests with PHP ${PHP_VERSION} (${DBMS})..."
                # Cleanup only, deliberately without an exit of its own: an
                # EXIT trap that does not exit leaves the status the script was
                # going to end with untouched, so a red run stays red.
                cleanupDb() {
                    docker rm -f "${DB_CONTAINER}" >/dev/null 2>&1 || true
                    docker network rm "${DB_NETWORK}" >/dev/null 2>&1 || true
                }
                trap cleanupDb EXIT
                startDatabase "${DB_IMAGE}" "${DB_PING}"
                runFunctional \
                    --network "${DB_NETWORK}" \
                    -e typo3DatabaseDriver=mysqli \
                    -e typo3DatabaseHost="${DB_CONTAINER}" \
                    -e typo3DatabaseName=func_test \
                    -e typo3DatabaseUsername=root \
                    -e typo3DatabasePassword="${DB_PASSWORD}"
                ;;
            *)
                echo "Unknown DBMS: ${DBMS} (expected mysql, mariadb or sqlite)"
                exit 1
                ;;
        esac
        ;;
    phpstan)
        echo "Running PHPStan with PHP ${PHP_VERSION}..."
        docker run --rm \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            "${PHP_IMAGE}" \
            php -d memory_limit=2G .Build/bin/phpstan analyse -c Build/phpstan.neon --no-progress
        ;;
    phpstan13)
        # Separate config and baseline, see CLAUDE.md: the v14 classes reference
        # core APIs that do not exist in the v13 dependency set.
        echo "Running PHPStan (v13 config) with PHP ${PHP_VERSION}..."
        docker run --rm \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            "${PHP_IMAGE}" \
            php -d memory_limit=2G .Build/bin/phpstan analyse -c Build/phpstan13.neon --no-progress
        ;;
    cgl)
        echo "Running coding standards check..."
        docker run --rm \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            "${PHP_IMAGE}" \
            php .Build/bin/php-cs-fixer fix --config=Build/php-cs-fixer.php --dry-run --diff --using-cache=no
        ;;
    lint)
        echo "Linting PHP files..."
        docker run --rm \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            "${PHP_IMAGE}" \
            bash -c 'find Classes Tests -name "*.php" -print0 | xargs -0 -n1 php -l > /tmp/lint.log 2>&1 || { cat /tmp/lint.log; exit 1; }'
        ;;
    *)
        echo "Unknown suite: ${TEST_SUITE}" >&2
        usage 1
        ;;
esac
}

EXIT_CODE=0
runSuite || EXIT_CODE=$?
echo ""
if [ ${EXIT_CODE} -eq 0 ]; then
    echo "✓ ${TEST_SUITE} passed"
else
    echo "✗ ${TEST_SUITE} failed (exit ${EXIT_CODE})"
fi
exit ${EXIT_CODE}
