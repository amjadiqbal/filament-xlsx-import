#!/usr/bin/env bash
# Local CI. GitHub workflows are disabled on this repo, so every change runs this and pastes the summary into the
# commit or PR. Each cell is PHP:Laravel:Filament. In a throwaway clone of HEAD, composer.json is narrowed to that
# Filament major and the Testbench that matches the Laravel major (10 for 12, 11 for 13), then
# composer update, Pint (once), PHPStan and Pest.
#   scripts/ci-local.sh
#   CELLS="8.5:13:5" scripts/ci-local.sh     (CELLS="" runs only the two extra checks at the end)
# Run it through Programming/.claude/package-publish/ci-serial.sh on the shared Mac. Tests committed code (HEAD) only.
set -uo pipefail
cd "$(dirname "$0")/.."
REPO=$(pwd)

CELLS=${CELLS-"8.2:12:4 8.3:12:4 8.3:12:5 8.4:13:4 8.4:13:5 8.5:12:5 8.5:13:5"}
RESULTS=(); FAILED=0; LINTED=0
step() { local name=$1; shift; printf '\n== %s\n' "$name"; if "$@"; then RESULTS+=("pass  $name"); else RESULTS+=("FAIL  $name"); FAILED=1; fi; }

php_bin() {
  local v=$1 p
  for p in "$(command -v "php$v" 2>/dev/null)" "$(brew --prefix 2>/dev/null)/opt/php@$v/bin/php"; do
    [ -n "$p" ] && [ -x "$p" ] && "$p" -r 'exit(PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION === $argv[1] ? 0 : 1);' "$v" && { echo "$p"; return; }
  done
  return 1
}

resolved() { # dir package -> installed version
  (cd "$1" && "$PHP" "$COMPOSER" show "$2" 2>/dev/null | awk '/^versions/ {print $NF}')
}

COMPOSER=$(command -v composer)
SHIM=$(mktemp -d); WORK=$(mktemp -d); trap 'rm -rf "$SHIM" "$WORK"' EXIT
BASE_PATH=$PATH

for cell in $CELLS; do
  IFS=: read -r v l f <<< "$cell"
  TAG="PHP $v Laravel $l Filament $f"
  PHP=$(php_bin "$v") || { RESULTS+=("FAIL  $TAG: PHP $v not found"); FAILED=1; continue; }
  # vendor/bin scripts start with "#!/usr/bin/env php": put this version first on PATH so they don't run on the default PHP.
  ln -sf "$PHP" "$SHIM/php"; export PATH="$SHIM:$BASE_PATH"
  if [ "$l" = "12" ]; then TB='^10.0'; else TB='^11.0'; fi
  DIR="$WORK/l$l-f$f-php$v"
  git clone --quiet --no-hardlinks "$REPO" "$DIR" && git -C "$DIR" checkout --quiet --detach "$(git rev-parse HEAD)"
  step "$TAG composer update" sh -c "\"$PHP\" \"$COMPOSER\" -d \"$DIR\" require --no-update --no-interaction 'filament/actions:^$f.0' && \"$PHP\" \"$COMPOSER\" -d \"$DIR\" require --no-update --no-interaction --dev 'filament/filament:^$f.0' 'orchestra/testbench:$TB' && \"$PHP\" \"$COMPOSER\" -d \"$DIR\" update -W --no-interaction --no-progress --prefer-dist -q"
  RL=$(resolved "$DIR" laravel/framework); RF=$(resolved "$DIR" filament/actions); RO=$(resolved "$DIR" openspout/openspout)
  step "$TAG resolved Laravel ${RL:-nothing}, Filament ${RF:-nothing}, openspout ${RO:-nothing}" sh -c "case '$RL' in v$l.*) ;; *) exit 1 ;; esac; case '$RF' in v$f.*) exit 0 ;; *) exit 1 ;; esac"
  if [ "$LINTED" = "0" ]; then
    step "$TAG pint --test" "$PHP" "$COMPOSER" -d "$DIR" lint
    LINTED=1
  fi
  step "$TAG phpstan" "$PHP" "$COMPOSER" -d "$DIR" analyse
  step "$TAG pest" "$PHP" "$COMPOSER" -d "$DIR" test
  rm -rf "$DIR"
done

# Lowest openspout that core allows (^4.23, PHP 8.3 at most): the reader must not rely on newer openspout APIs.
v=8.3; PHP=$(php_bin "$v") && { ln -sf "$PHP" "$SHIM/php"; export PATH="$SHIM:$BASE_PATH"
  DIR="$WORK/lowest-openspout"
  git clone --quiet --no-hardlinks "$REPO" "$DIR" && git -C "$DIR" checkout --quiet --detach "$(git rev-parse HEAD)"
  step "PHP $v Laravel 12 Filament 4 openspout 4.23 composer update" sh -c "\"$PHP\" \"$COMPOSER\" -d \"$DIR\" require --no-update --no-interaction 'openspout/openspout:4.23.*' 'filament/actions:^4.0' && \"$PHP\" \"$COMPOSER\" -d \"$DIR\" require --no-update --no-interaction --dev 'filament/filament:^4.0' 'orchestra/testbench:^10.0' && \"$PHP\" \"$COMPOSER\" -d \"$DIR\" update -W --no-interaction --no-progress --prefer-dist -q"
  step "PHP $v openspout $(resolved "$DIR" openspout/openspout) pest" "$PHP" "$COMPOSER" -d "$DIR" test
  rm -rf "$DIR"; }

# PHP 8.2 with Filament 5: Pest's Livewire plugin needs PHP 8.3 for Livewire 4, so this cell installs the package
# without dev tools and loads every class against the installed Filament 5 instead of running the suite.
v=8.2; PHP=$(php_bin "$v") && { ln -sf "$PHP" "$SHIM/php"; export PATH="$SHIM:$BASE_PATH"
  DIR="$WORK/php82-f5"
  git clone --quiet --no-hardlinks "$REPO" "$DIR" && git -C "$DIR" checkout --quiet --detach "$(git rev-parse HEAD)"
  "$PHP" -r '$f = $argv[1]."/composer.json"; $j = json_decode(file_get_contents($f), true); unset($j["require-dev"]); file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));' "$DIR"
  step "PHP $v Laravel 12 Filament 5 composer update --no-dev" sh -c "\"$PHP\" \"$COMPOSER\" -d \"$DIR\" require --no-update --no-interaction 'filament/actions:^5.0' 'laravel/framework:^12.0' && \"$PHP\" \"$COMPOSER\" -d \"$DIR\" update --no-dev -W --no-interaction --no-progress --prefer-dist -q"
  step "PHP $v Filament $(resolved "$DIR" filament/actions) every class loads" "$PHP" -r '
    require $argv[1]."/vendor/autoload.php";
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[1]."/src")) as $f) {
      if ($f->getExtension() !== "php") continue;
      $class = "AmjadIqbal\\FilamentXlsxImport\\".str_replace(["/", ".php"], ["\\", ""], substr($f->getPathname(), strlen($argv[1]."/src/")));
      if (! class_exists($class)) { fwrite(STDERR, "missing $class\n"); exit(1); }
      echo "loaded $class\n";
    }' "$DIR"
  rm -rf "$DIR"; }

printf '\n== Summary (%s, %s)\n' "$(git rev-parse --short HEAD)" "$(date '+%Y-%m-%d %H:%M')"
printf '%s\n' "${RESULTS[@]}"
exit $FAILED
