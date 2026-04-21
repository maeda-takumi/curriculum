# 71ページ 不具合整理メモ（2026-04-21）

## 1. 現象（現在地）
- `?page=71` で表示はされるが、「初期崩れ → JSで整う」挙動が安定しない。
- Console には主に `POST /cdn-cgi/rum 404` が出る。
- ただしこの `rum 404` は計測系であり、表示崩れの主因ではない可能性が高い。

---

## 2. ここまでの確認結果（事実）

### 2.1 `_buildManifest.js` の状態
- 以前は `...CB()self.__BUILD_MANIFEST...` のような**重複連結/破損**が確認された。
- 現在は、`/curriculum/phase/71_files/_buildManifest.js` が読み込まれており、
  manifestは1ブロックで読めている状態。

### 2.2 `static/chunks/*` 参照の存在
- manifestには以下の参照がある：
  - `static/chunks/63693846a3768e1a.js`
  - `static/chunks/1241346909709dd5.js`
  - `static/chunks/7a3c3fdf2c1f5beb.js`
  - `static/chunks/a946c26f1dc00c95.js`
- `7a3c...js` の中身を見ると、さらに `static/chunks/*.js` / `*.css` を読む構成（Turbopackローダー）。

### 2.3 71と61の比較
- `61.html` も `71.html` も、`__NEXT_DATA__` とクライアント実行用スクリプトを持つ。
- 71にも `animateOnLoad` / `site-animations-bootstrap` 系ログが出るため、
  **JS実行自体は起きている**。
- よって「再描画処理が存在しない」ではなく、
  **必要な条件/依存解決が揃わず期待した見た目更新に至っていない**可能性が高い。

---

## 3. HTML/実行の仕組み（要点）

このページは「静的HTML + Next/Turbopackのクライアント処理」で動く構造。

1. 初期HTMLを表示
2. `_buildManifest.js` がページchunk情報を提供
3. `/published/[docId]` 用chunk（例: `7a3c...js`）を経由して追加chunkをロード
4. hydrate（クライアント側再構築）やクラス付与で最終見た目が整う

=> どこかで chunk 解決が不完全だと、
   「表示は出るが整わない/再描画されない」状態になりうる。

---

## 4. いまの本質的な問題点

1. **参照パスの不整合リスク**
   - manifestは `static/chunks/...` を参照
   - 実ファイルは `phase/71_files/...` 側にある
   - URL解決（rewrite/alias）がないと不一致が起きる

2. **外部CDN依存とローカル配置の混在**
   - 一部はローカル、元データはGamma配信前提
   - build単位で揃っていないと不安定化しやすい

3. **見た目崩れの補正がJS依存**
   - 初期CSSだけで完結せず、hydrate/クラス付与後に整う前提の可能性

---

## 5. 対応方針（推奨）

### 方針A: 既存ファイルを活かしてルーティングで解決（最短）
`/curriculum/static/chunks/<name>` を  
`/curriculum/phase/71_files/<name>` へマッピングする。

必要なら `/curriculum/_next/static/chunks/` 側も同様にマップ。

#### nginxイメージ
```nginx
location ^~ /curriculum/static/chunks/ {
    alias /path/to/webroot/curriculum/phase/71_files/;
    try_files $uri =404;
}
location ^~ /curriculum/_next/static/chunks/ {
    alias /path/to/webroot/curriculum/phase/71_files/;
    try_files $uri =404;
}