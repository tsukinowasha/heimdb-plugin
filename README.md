# HeimDB プラグイン

[HeimDB](https://github.com/tsukinowasha/heimdb) の手続き（`CALL heimdb_*`）を、SQL の外側、つまり WordPress の管理画面と wp-cli から使えるようにする WordPress プラグイン。

- ブランチの作成、削除、上限、ブランチだけの権限のユーザー
- 親との差分（テーブルごと → 行ごと → 1 行の両側の値の比較）、競合の解決、空打ち、マージ
- 親の変更のブランチへの取り込み（HeimDB 0.2.33 から）
- 時間旅行: ある時刻からの変更、1 行のその時点の値と今の値の比較、復元（と下見）、過去の時点からのブランチ
- マージ、取り込み、復元の直後の、ワンクリックでの取り消し（`undo`）
- マージから外す表、バックアップ、GC、`heimdb_status`

## 設定

手続きは管理者（SUPER 権限）しか呼べない。WordPress そのものはサイトのユーザーのままにして、このプラグインにだけ管理者の接続を `wp-config.php` で渡す。

```php
define( 'HEIMDB_ADMIN_USER', 'root' );
define( 'HEIMDB_ADMIN_PASSWORD', '...' );
// define( 'HEIMDB_ADMIN_HOST', 'heimdb-host:3306' );   // 省けば DB_HOST
```

`HEIMDB_ADMIN_USER` がなければ `DB_USER` でつなぐ（wp-cli だけを root で動かす HeimDB の `compose.yaml` の構成なら、wp-cli はそのまま使える）。

> この定数は、そのサイトで PHP を動かせる人には読める。ブランチを管理するサイト（ふつうは本番）にだけ置き、ステージングのサイトには置かない。ステージングのサイトは `wp heimdb branch grant` で作るブランチだけの権限のユーザーでつなぐ。

### ステージングのサイト（HeimDB 0.2.15 から）

HeimDB を `-branch-procedures`（または環境変数 `HEIMDB_BRANCH_PROCEDURES=1`）で起動すると、ステージングのサイトでも、管理者の定数を置かずに、サイトのユーザー（ブランチだけの権限）のまま次のことができる。

- 自分のブランチの差分（テーブルごと、行ごと、競合した列の両側の今の値）
- 行を指定した競合の解決（記録した人と時刻が残り、本番の管理者がそれを見てマージする）
- 時間旅行と復元（ブランチを作った時刻まで）と、その取り消し

マージ、親の変更の取り込み、ブランチの作成と削除、上限、ユーザー、すべての競合をまとめて解決すること、マージから外す表を変えること、バックアップ、GC、`heimdb_status` は、管理者だけのまま（本番のサイトから行う）。ブランチのユーザーは親を読めないので、行の比較の画面には、親の値は競合した列だけを出す。ユーザーには、ブランチのデータベースの EXECUTE 権限が要る（`wp heimdb branch grant` の `GRANT ALL` に含まれる）。

差分は、分岐の後に親（本番）で変わった値もブランチのユーザーに見せる。ブランチで別々のテナントを分けているなら、このフラグは付けない。

時刻は、オフセットがなければサイトのタイムゾーン（設定 > 一般）で読み、HeimDB にはいつもオフセット付きの UTC で渡す。

## 管理画面

メニューの **HeimDB**。

| 画面 | できること |
| --- | --- |
| ブランチ | 一覧、作成（親、過去の時点、上限） |
| ブランチ › staging | テーブルごとの差分、競合の数、すべての競合をどちらかに解決、空打ち、マージ、親の変更の取り込み（空打ち、競合した列で採る側）、上限、ブランチだけの権限のユーザー、削除（このサイトがつないでいるブランチは、このサイトからは消せない） |
| ブランチ › staging › wp_posts | 行ごとの判定（判定ごとに絞り込み）、行ごとの解決。親にいない投稿者の投稿など、親の側でしか解決できない競合（HeimDB 0.2.13 から）には親のボタンだけを出す |
| ブランチ › … › ID=1 | 1 行の「親の分岐の時点」「親の今」「ブランチの今」の値を並べ、競合した列を目立たせる。長い文字列は行ごとの差分。この画面からも解決できる。両側で足された同じ投稿（自然キーが同じ）は、親の側の別の ID の行を「親の今」に出す。主キーのない表（HeimDB 0.2.20 から）の行は、見えない行 ID（`__heimdb_row_id=N`）で呼ばれ、SQL で 1 行を引けないので、競合した列の値だけを出す |
| 時間旅行 | 時刻（「1 時間前」などのボタン）からの変更の一覧、1 行の当時と今の比較、復元の下見と復元（コメントとユーザー以外 / すべて / 選んだテーブル）、その時点からのブランチ。選んだテーブルが組（`wp_posts` と `wp_postmeta` など）の片方だけなら、HeimDB（0.2.14 から）の警告を出し、まとめて戻す下見へのボタンを出す |
| 状態 | マージから外す表、バックアップ、GC、`heimdb_status` |

管理バーには、このサイトがつないでいるブランチを出す（本番以外は赤）。

ステージングのサイトがブランチのユーザーでつなぐときは、メニューの **HeimDB** から、そのブランチの差分と解決、時間旅行、状態（マージから外す表の一覧）だけを出す。差分の行には、解決を記録した人と時刻を出す（HeimDB 0.2.15 から。本番のサイトでも出る）。

マージ、取り込み、復元の結果の通知に、取り消しのボタンを出す（HeimDB が結果の最後の行で返す `heimdb_restore` の引数を、ボタンが送る）。プラグインは操作の記録を持たない（データベースに置くと、マージや復元で記録そのものが入れ替わったり巻き戻ったりするため）。後から取り消すときは、時間旅行の画面で、操作の前の時刻に復元する。wp-cli は操作の後に、取り消しのコマンドを出す。0.2.1 までは記録をユーザーのメタ `heimdb_history` に持っていた。残っていても使わず、プラグインを削除すると消える（`wp user meta delete <ユーザー> heimdb_history` でも消せる）。

## wp-cli

```sh
wp heimdb branch list
wp heimdb branch create staging [--parent=main] [--at="2026-10-18 09:00"] [--max-size=2G] [--max-time=30s]
wp heimdb branch grant staging staging [--password=...] [--max-connections=20]   # このサイトの DB_USER と管理者は断る。いるユーザーは、渡したものだけ変える
wp heimdb branch limit staging 2G 30s
wp heimdb branch delete staging [--yes]

wp heimdb diff staging                       # テーブルごとの要約（--all で unchanged も）
wp heimdb diff staging wp_posts              # 行ごと（'*' ならすべて）
wp heimdb diff staging --conflicts           # 競合だけ
wp heimdb resolve staging wp_posts ID=1 branch     # branch | parent | none、'*' も可（'*' の branch で残る、親の側でしか解決できない競合は警告に出す）
wp heimdb merge staging --dry-run
wp heimdb merge staging --yes                # 成功すれば戻すコマンドを出す
wp heimdb sync staging --dry-run             # 親の変更をブランチに取り込む（HeimDB 0.2.33 から）
wp heimdb sync staging [--side=branch|parent] --yes   # 競合があれば、--side を渡さないと断る。成功すれば戻すコマンドを出す

wp heimdb changes main "3 hours ago" [--tables=wp_users,wp_usermeta]
wp heimdb row main wp_options option_name=blogname --at="1 day ago" --compare
wp heimdb restore main "2026-10-18 09:00" --preview
wp heimdb restore main "2026-10-18 09:00" [--tables=wp_posts,wp_postmeta | --tables='*'] --yes
# 組の片方だけを戻す（例えば --tables=wp_posts）と、まとめて戻すコマンドを警告に出す（HeimDB 0.2.14 から）

wp heimdb exclude add 'wp_yoast_%' | remove 'wp_yoast_%' | list
wp heimdb status | backup [<name>] | gc
```

どれも `--format=json` などが使える（表を出すもの）。HeimDB のエラーは、番号を付けて終了コード 1 で返す（`[51004]` など）。

ステージングのサイトの wp-cli（ブランチのユーザー、HeimDB 0.2.15 の `-branch-procedures`）では、そのブランチの `diff`、行を指定した `resolve`、`changes`、`row`、`restore` が使える。ほかは断られる。

## フック

マージは DB サーバの中の操作なので、HeimDB からは PHP のフックを呼べない。このプラグインが手続きを呼んだ前後に出す（管理画面からでも wp-cli からでも）。

| フック | 引数 |
| --- | --- |
| `heimdb_before_merge` | `$branch`, `$dry_run` |
| `heimdb_after_merge` | `$branch`, `$rows`（`heimdb_branch_merge` の結果）, `$undo`（戻すための引数か null） |
| `heimdb_before_sync` | `$branch`, `$side`（`branch`、`parent` か null）, `$dry_run` |
| `heimdb_after_sync` | `$branch`, `$rows`（`heimdb_branch_sync` の結果）, `$undo` |
| `heimdb_before_restore` / `heimdb_after_restore` | `$branch`, `$at`, `$tables`（, `$rows`） |
| `heimdb_branch_created` / `heimdb_branch_deleted` | `$name`（, `$branch` の行） |
| `heimdb_capability`（フィルタ） | 画面を使える権限（既定 `manage_options`。マルチサイトでは、HeimDB の操作が全サイトに効くので `manage_network_options`） |

```php
// 例: Yoast の表をマージから外しているなら、マージの後に索引を作り直す（HeimDB の docs/plugins.md）
add_action( 'heimdb_after_merge', function ( $branch, $rows ) {
	foreach ( $rows as $row ) {
		if ( 'excluded' === $row['status'] && $row['inserts'] + $row['updates'] + $row['deletes'] > 0 ) {
			wp_schedule_single_event( time(), 'my_rebuild_yoast_index' );
			return;
		}
	}
}, 10, 2 );
```

PHP から直接使うなら `Heimdb\Client::instance()`（`branch_diff()`、`branch_merge()`、`branch_sync()`、`restore()`、`row()` など）。HeimDB のエラーは `Heimdb\Error` で、`getCode()` が番号、`$reason` が理由の語（`unresolved_conflicts` など）。

## 開発

```sh
docker compose -f dev/compose.yaml up -d      # HeimDB（127.0.0.1:3317）と WordPress（http://localhost:8090）
dev/wp core install --url=http://localhost:8090 --title=Dev --admin_user=admin --admin_password=admin --admin_email=dev@example.com
dev/wp plugin activate heimdb
HEIMDB_SITE_DB=wordpress@staging dev/wp post update 1 --post_title='from staging'   # ブランチのサイトとして書く
php tests/run.php                              # WordPress の要らない部分の単体テスト
tests/e2e.sh                                   # 開発環境で、CLI（ブランチ → 競合 → 解決 → マージ → 取り消し → 復元 → ユーザー、上限、GC）と管理画面（表示、POST、取り消し）、ブランチのユーザー（CLI とステージングのサイト）を通しで確かめる
docker run --rm -v "$PWD:/app:ro" wordpress:cli-php8.4 sh /app/tests/i18n-check.sh   # 翻訳の漏れと作り直し忘れ
```

ステージングのサイト（http://localhost:8091）は、ブランチ staging にユーザー staging でつなぐ（`dev/wp heimdb branch create staging`、`dev/wp heimdb branch grant staging staging --password=staging-secret` の後に `docker compose -f dev/compose.yaml --profile staging up -d`）。開発環境の HeimDB は `HEIMDB_BRANCH_PROCEDURES=1` で動く。

開発環境の HeimDB は既定で 0.2.33（データ形式が版 6 なので、0.2.32 以前で作ったボリュームは開くと上がり、古い版では開けなくなる）。ほかの版は `HEIMDB_VERSION=0.2.12 docker compose -f dev/compose.yaml up -d` で立て、`tests/e2e.sh` も同じ `HEIMDB_VERSION` で流す（その版にない機能の確かめは飛ばす）。

GitHub Actions（`.github/workflows/ci.yml`）は、push と pull request で、この 3 つ（単体テストは PHP 8.2、8.4、8.5 で、E2E は HeimDB 0.2.12、0.2.14、0.2.15、0.2.16、0.2.24、0.2.33 で）を流す。

翻訳（日本語）は `languages/` にある。`heimdb-ja.po` が元で、`.mo` と `.l10n.php`（WordPress 6.5 から）はそこから作る。HeimDB の結果の語（`changed`、`restore rows` などの状態）は画面で訳すが、`detail` の説明文は HeimDB の原文のまま出す。文言を変えたら、次で作り直す。

```sh
docker compose -f dev/compose.yaml run --rm -T -v "$PWD/languages:/out" --entrypoint sh cli -c '
  wp i18n make-pot wp-content/plugins/heimdb /out/heimdb.pot --slug=heimdb --domain=heimdb --exclude=dev,tests,languages &&
  cd /out && wp i18n update-po heimdb.pot heimdb-ja.po'
# heimdb-ja.po の空の msgstr を訳してから
docker compose -f dev/compose.yaml run --rm -T -v "$PWD/languages:/out" --entrypoint sh cli -c '
  cd /out && wp i18n make-mo heimdb-ja.po . && wp i18n make-php heimdb-ja.po .'
```

要件: WordPress 6.0 以上、PHP 8.2 以上（8.4 以上を勧める。開発環境と E2E は 8.4）。HeimDB 0.2.12、0.2.14、0.2.15、0.2.16、0.2.24、0.2.33 で確かめた（0.2.12 でも動く。親の側でしか解決できない競合の扱いは 0.2.13 から、組の片方だけの復元の警告は 0.2.14 から、ステージングのサイトからの差分、解決、復元と、解決を記録した人の表示は 0.2.15 から。ブロックテーマのサイトで両側に自動で作られるナビゲーションのような、中身も同じ重複の投稿は、0.2.16 から競合にならず skip になる。複数列の主キー（0.2.19 から）と主キーのない表（0.2.20 から）の差分も扱う。親の変更の取り込みは 0.2.33 から）。
