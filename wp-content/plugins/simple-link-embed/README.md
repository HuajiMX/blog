# Simple Link Embed

WordPress ブロックエディターで URL を入力するだけでリンクカードを生成するプラグインです。  
OGP を取得して、タイトル、説明、画像、サイト名をカード表示します。

この `README.md` は GitHub リポジトリ向けの案内です。  
WordPress.org に表示される公開説明は [`readme.txt`](./readme.txt) を正とします。

## 現在の状態

- 対象バージョン: `1.0.3`
- ブロック名: `simple-link-embed/card`
- 配布形態: WordPress.org 配布を前提としたブロックプラグイン

## 主な機能

- URL から OGP 情報を取得してリンクカードを生成
- Gutenberg `LinkControl` による URL 選択
- URL 単位の transient キャッシュ
- YouTube / X(Twitter) 向けの補完処理
- サイト favicon を優先し、未取得時は同梱アイコンにフォールバック
- 任意の GA4 クリック計測

## 主な構成

- [`simple-link-embed.php`](./simple-link-embed.php)
  - プラグインのエントリポイント
- [`inc/class-ogp-fetcher.php`](./inc/class-ogp-fetcher.php)
  - OGP 取得、HTML 解析、外部通信まわり
- [`inc/class-renderer.php`](./inc/class-renderer.php)
  - フロントのカード描画
- [`assets/block.js`](./assets/block.js)
  - ブロックエディター UI
- [`readme.txt`](./readme.txt)
  - WordPress.org 向け公開説明と External Services 記載

## 開発時によく使うコマンド

```bash
composer install
composer test
composer preflight
```

- `composer test`: PHPUnit 実行
- `composer preflight`: 配布前チェックと配布 ZIP 生成

## ドキュメント

ドキュメントは `docs/` 配下にまとめています。最初は [`docs/README.md`](./docs/README.md) から見るのがおすすめです。

- [`docs/README.md`](./docs/README.md)
  - ドキュメント入口
- [`docs/PROJECT_OVERVIEW.md`](./docs/PROJECT_OVERVIEW.md)
  - プロジェクト概要とコードベースの見取り図
- [`docs/REQUIREMENTS.md`](./docs/REQUIREMENTS.md)
  - 仕様とアーキテクチャ上のガードレール
- [`docs/RELEASE_WORDPRESS_ORG.md`](./docs/RELEASE_WORDPRESS_ORG.md)
  - WordPress.org リリース手順

## リリースについて

- WordPress.org に出す説明文は `readme.txt` を更新します
- 配布前は `composer preflight` を実行します
- SVN への反映手順は [`docs/RELEASE_WORDPRESS_ORG.md`](./docs/RELEASE_WORDPRESS_ORG.md) を参照します

## 補足

このプラグインは、編集時にリンク先ページのメタデータを取得し、表示時にはリンク先の `og:image` や favicon を利用する場合があります。公開時の説明責任は `readme.txt` の `External Services` セクションで管理します。
