#!/bin/sh
# 翻訳が文言に追いついているかを確かめる（wordpress:cli のコンテナの中で、プラグインを /app に置いて動かす）。
#
#   docker run --rm -v "$PWD:/app:ro" wordpress:cli-php8.4 sh /app/tests/i18n-check.sh
#
# - コードの文言のうち、languages/heimdb-ja.po に訳のないもの
# - heimdb-ja.po から作った .mo と .l10n.php が、リポジトリのものと違う（作り直し忘れ）
set -eu
work=$(mktemp -d)
cp -r /app/languages/. "$work/"
cd "$work"

wp i18n make-pot /app new.pot --slug=heimdb --domain=heimdb --exclude=dev,tests,languages --skip-audit --quiet
cp heimdb-ja.po updated.po
wp i18n update-po new.pot updated.po --quiet

# 訳のない項目（ヘッダを除く、空の msgstr）
missing=$(awk '
	/^msgid / { id = $0; next }
	/^msgstr(\[0\])? ""$/ { pending = 1; next }
	pending && /^"/ { pending = 0; next }
	pending { if (id != "msgid \"\"") { print id; n++ } pending = 0 }
	END { if (pending && id != "msgid \"\"") print id }
' updated.po)
status=0
if [ -n "$missing" ]; then
	echo "Untranslated strings in languages/heimdb-ja.po (run wp i18n update-po and translate them):"
	echo "$missing"
	status=1
fi

# .mo と .l10n.php を作り直して比べる
mkdir built
wp i18n make-mo heimdb-ja.po built --quiet
wp i18n make-php heimdb-ja.po built --quiet
for f in heimdb-ja.mo heimdb-ja.l10n.php; do
	if ! cmp -s "built/$f" "$f"; then
		echo "languages/$f is out of date; rebuild it from heimdb-ja.po (see README)."
		status=1
	fi
done

[ "$status" = 0 ] && echo "Translations are complete and up to date."
exit "$status"
