#!/bin/sh
# Render golden outputs with the current PHP app. Run in the PHP container from /var/www/html/daybook_app:
#   sh tools/make_golden.sh
set -e
APP=/var/www/html
OUT=$PWD/tests/golden
mkdir -p "$OUT/view"
render() { # <fixture file> <golden name>
    tmp=$(mktemp -d)
    cp "$1" "$tmp/BOOK.xlsx"
    (cd "$tmp" && php -d display_errors=stderr -d include_path=$APP -r "include '$APP/view.php';" > "$OUT/view/$2.html" 2>/dev/null)
    rm -rf "$tmp"
}
for n in 1 2 3 4 5 6 7 8 9; do render tests/fixtures/aug2026/$n.xlsx aug2026_$n; done
render tests/fixtures/feb2026/BOOK.xlsx feb2026
render tests/fixtures/synthetic/edge20.xlsx edge20
render tests/fixtures/synthetic/noise21.xlsx noise21

# JV separation over the nine August files (numbered 1..9, as the app stores them)
php -d display_errors=stderr -r "
require '$APP/vendor/autoload.php'; require '$APP/jvSeparation.php';
list(\$jv, \$nonJv) = separateJVAndNonJVNumbers('tests/fixtures/aug2026');
echo json_encode(array('jv' => \$jv, 'nonJv' => \$nonJv), JSON_PRETTY_PRINT);" > "$OUT/jv_aug2026.json"
php -d display_errors=stderr -r "
require '$APP/vendor/autoload.php'; require '$APP/jvSeparation.php';
\$d = sys_get_temp_dir() . '/jvsyn'; @mkdir(\$d); copy('tests/fixtures/synthetic/edge20.xlsx', \"\$d/1.xlsx\"); copy('tests/fixtures/synthetic/noise21.xlsx', \"\$d/2.xlsx\");
list(\$jv, \$nonJv) = separateJVAndNonJVNumbers(\$d);
echo json_encode(array('jv' => \$jv, 'nonJv' => \$nonJv), JSON_PRETTY_PRINT);" > "$OUT/jv_synthetic.json"
ls -la "$OUT/view"
