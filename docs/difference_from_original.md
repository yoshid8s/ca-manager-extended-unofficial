# OP-CIP公式CA Manager v0.4.3 → 独自拡張版 差分整理レポート

本レポートは、Originator Profile技術研究組合（OP-CIP）提供の<br/>
公式CAマネージャー(v0.4.3)をもとに、独自拡張したCAマネージャー拡張版（非公式）の差分を整理したものです。

独自拡張したCAマネージャー拡張版を開発したのは、WordPress利用者の立場では<br/>
公式版は、使いづらかったためです。

例えば、
- 本来、提供側が配慮すべき「無害な改ざん検知」への対処がされていないため、OP拡張機能のエラーが頻発する
- 編集ページ単位でのCA発行ができない
- ページ数が多い場合の一括CA発行ができない

などです。

公式CAマネージャーに利用者視点での使いやすさがなければ、<br/>
どれだけ社会性の高いOPであっても、OP実装するWebサイトは増えないと考えられます。<br/>
本レポートを参考にしていただき、公式CAマネージャーに利用者視点でのアップデートを実行いただけたら幸いです。<br/>

## 1. 差分整理レポート対象の独自拡張版バージョン

本レポートの差分整理対象は、[v0.4.8-x-share](https://github.com/yoshid8s/ca-manager-extended-unofficial/releases)  です。

## 2. ファイル構成の差分概要

### 追加ファイル

- `assets/css/admin-ui.css`
- `assets/js/admin-ui.js`
- `includes/admin-ad-content.php`
- `includes/admin-ad-menus.php`
- `includes/admin-assets.php`
- `includes/admin-embedded-content.php`
- `includes/admin-ui.php`
- `includes/class-cam-ad-db.php`
- `includes/context-ads.php`
- `includes/front-ad-insert.php`
- `includes/front-assigned-ad.php`
- `includes/helpers-ui.php`

### 変更ファイル

- `README.md`
- `ca-manager.php`
- `includes/activator.php`
- `includes/admin.php`
- `includes/class-uca.php`
- `includes/issue.php`
- `includes/post.php`

## 3. 変更規模

公式版のv0.4.3 は比較的シンプルな記事CA発行プラグインでしたが、独自拡張版では、特に以下のファイルが大幅拡張されています。

- `includes/issue.php`: 319行 → 3174行
- `includes/admin.php`: 315行 → 3013行
- `includes/post.php`: 101行 → 434行
- `includes/class-uca.php`: 106行 → 218行

大幅拡張となった理由は、<br/>
記事CA発行プラグインから、記事CA・広告CA・埋め込みコンテンツCA・広告配信/申込管理を含む統合CA管理プラグインへ拡張したためです。

## 4. 主な追加機能

### 4.1 CAマネージャー編集UI

`includes/admin-ui.php` により、投稿・固定ページ編集画面でCAを管理する独自UI（以下の例）が追加されています。

<img width="1150" height="625" alt="image" src="https://github.com/user-attachments/assets/60562252-5c81-4065-ac20-1b96f5fe0e9b" />


新たに拡張したのは、以下を一元管理するUIです。

- 記事CA
- 広告CA
- 埋め込みコンテンツCA
- テキスト選択によるselector管理
- 画像選択によるCA対象登録
- 編集責任者・執筆者・権利者・出典URLなどのメタ情報

公式v0.4.3 は指定したCSSセレクターを持つページに一括してCA発行はできましたが<br/>
CAのプロパティである編集者、著者、ジャンルなどの情報の登録はできませんでした。<br/>
また、個別編集ページにCA発行機能がないため、ページ単位で編集者や著者が異なるケースが<br/>
あるようなメディアの場合には、CAマネージャーだけではなく、利用者側が<br/>
独自に機能追加をCMSに行う必要がありました。

### 4.2 記事CAの安定化

`includes/issue.php` で、本文要素に `op-body-*` IDを付与する処理が大幅に拡張されています。

主な改善点:

- p / h1〜h6 / blockquote / figcaption / pre などを対象化
- テキスト正規化後のSHA1ベースで安定IDを生成
- `TextTargetIntegrity` の対象を段落単位・見出し単位に分割
- 既存CAとのマージ処理
- 記事CA一括発行処理
- 警告理由の検出
- メタ情報の一時退避・復元

特に、HTMLで利用しているCSSセレクターを意識したことのない<br/>
非エンジニアのCMS利用者が<br/>
CA本来の機能である、段落単位に分解して自動的にCA発行できるのは、<br/>
WordPress実運用上かなり重要な改善であると考えます。<br/>

### 4.3 埋め込みコンテンツCA

「埋め込みコンテンツCA」は、第三者発信によるコンテンツであることを<br/>
記事発信主体が「自己宣言」するものです。<br/>

<img width="1149" height="717" alt="image" src="https://github.com/user-attachments/assets/7e7ae15f-6f85-4c8c-a936-cea1a9b1d775" />

<br/><br/><br/>
記事内に含まれる発信者以外の第三者が作成した文章や写真などの引用に対するCA発行を<br/>
総称する「埋め込みコンテンツCA」というコンセプトを独自に拡張しました。<br/>
これは、わたしからのOPへの提案であり、公式には存在しないことに注意してください。<br/>
<br/>
理想的には、第三者のコンテンツがある場合、当該主体がOP/CAを持ち、それを記事内に埋め込むべきです。<br/>
しかしながら、すべてのWebサイト運用者がOP/CAを持っていない、OPが普及途上にある現状で<br/>
記事に第三者のコンテンツが含まれる場合には、記事発信者が自分のコンテンツではなく、<br/>
第三者のものであることを「自己宣言」することが現実的な対応と考えます。<br/>
<br/>
「埋め込みコンテンツCA」というコンセプトをどう考えるか、OP-CIP内部でご議論いただき、<br/>
あるべき方向性を示していただけたら幸いです。<br/>

CAマネージャー拡張版では以下ファイルにおいて<br/>
本文中の引用テキストや埋め込み画像を独立したCA対象として扱う仕組みが追加されています。<br/>

- `includes/admin-embedded-content.php`
- `includes/admin-ui.php`
- `includes/issue.php` 

主な追加点:

- `_cam_embedded_items` による複数埋め込み対象の管理
- テキストCA: `TextTargetIntegrity`
- 画像CA: `ExternalResourceTargetIntegrity`
- subject_type `Image` 対応
- 権利者・出典URL・備考の保持
- 本文CAから埋め込み対象を除外する処理

これは、第三者によるテキスト引用や外部由来画像を「自己申告型CA」として分離する設計になっています。

### 4.4 OnlineAd / 広告CA対応

この広告CAにおいても、「埋め込みコンテンツCA」同様のコンセプトで<br/>
第三者（広告主）作成の広告画像を記事発信者が実在生を確認した広告主の広告であると<br/>
「自己宣言」する考え方で設計しています。<br/>
<br/>
`includes/class-uca.php` に`subject_type` を追加し、<br/>
`Article` だけでなく `OnlineAd` と `Image` を扱えるようになっています。<br/>
<br/>
OnlineAdでは、以下のような構造に拡張されています。

- `credentialSubject.type: OnlineAd`
- `headline` ではなく `name` を利用
- `landingPageUrl` を追加
- 広告画像に `ExternalResourceTargetIntegrity` を付与
- 広告画像selectorとintegrityをCA targetとして持たせる

「埋め込みコンテンツCA」で述べたように、OP実装メディアに広告を出す広告主が<br/>
すべてOP実装しているわけではない現状での現実的な解決策です。<br/>

OP実装メディアや広告会社が、OP実装メディアへの広告提案を<br/>
広告主に行う際に、そのWebサイトや広告データにOP実装を強いるのは<br/>
広告セールスの現場の視点から、現実的ではありません。<br/>
OPが普及するまでの現実的な対応として、OP-CIPに検討いただけたら幸いです。

### 4.5 広告申込・承認・配信管理DB

CAマネージャーを広告配信/広告申込ワークフローまで拡張するために<br/>
`includes/class-cam-ad-db.php` により、プラグイン有効化時に独自テーブルが作成される設計としています。<br/>
<br/><br/><br/>
<img width="862" height="631" alt="image" src="https://github.com/user-attachments/assets/fefb80af-6fb8-4050-83e7-213e3dd7f618" />
<br/><br/><br/>
作成される主なテーブル:

- `cam_advertisers`: 広告主
- `cam_ad_slots`: 広告枠
- `cam_ad_applications`: 広告申込
- `cam_ad_application_items`: 広告申込明細
- `cam_ad_reviews`: 審査履歴
- `cam_ad_delivery_logs`: 配信ログ
- `cam_ad_daily_stats`: 日次集計


### 4.6 コンテキスト広告

記事ジャンルや本文・タイトルとの一致を使って広告を表示する仕組みが<br/>
`includes/context-ads.php` と `includes/front-ad-insert.php` に組み込まれています。<br/>
<br/><br/><br/>

<img width="874" height="724" alt="image" src="https://github.com/user-attachments/assets/9aaf5e68-2934-4a38-a1f4-8e40ca10c2a2" />

<br/><br/><br/>
以下のショートコードを、記事ページの上段・中段・下段の３箇所に配置し、<br/>
記事CAのジャンル設定、広告申込時のジャンル設定がなされると<br/>
申込広告単価も含めた複数広告主の競争入札の結果、条件にマッチする広告が表示されます。<br/>
<br/>
追加ショートコード:

- `[cam_ad_top]`
- `[cam_context_ad_top]`
- `[cam_context_ad_middle]`
- `[cam_context_ad_bottom]`

また、クリック・インプレッション・下部到達・時間到達などをログ化する処理も追加されています。

### 4.7 割当広告

広告申込を特定記事に割り当てて表示する仕組みが<br/>
`includes/front-assigned-ad.php` により追加されています。<br/>
<br/><br/><br/>

<img width="1150" height="718" alt="image" src="https://github.com/user-attachments/assets/99217619-83b7-46f5-a2fe-a6f3bebbed68" />

<br/><br/><br/>
これは、上記のコンテキスト広告よりも優先されます。<br/>
例えば、大谷翔平選手の記事など<br/>
特定の記事を独占したい広告主向けの機能です。<br/>
<br/>
追加ショートコード:

- `[cam_assigned_ad_top]`
- `[cam_assigned_ad_middle]`
- `[cam_assigned_ad_bottom]`

コンテキスト一致ではなく、記事別に広告申込IDを割り当てるモデルです。

### 4.8 OGP / OPG拡張

X共有やOGP拡張を視野に入れた実験的な機能で<br/>
`includes/post.php` に以下が追加されています。<br/>
<br/><br/><br/>

<img width="906" height="710" alt="image" src="https://github.com/user-attachments/assets/55670cae-aa29-41f1-9c7a-0e1d779e41e8" />

<br/><br/><br/>
この機能により、OP向けに独自に拡張した<br/>
OGPメタデータがHTMLに埋め込まれます。<br/>

- `og:op:type`
- `og:op:cas`
- `/op-share/{hash}` 形式の共有ページ生成
- `TextBlockAttestation` 用のOGPメタ出力


### 4.9 画像integrity処理の拡張

WordPressが行うレスポンシブ画像最適化処理へのCA発行をスムーズに処理するために<br/>
画像に `integrity` 属性を付与し、srcsetの画像情報から複数hashを扱う処理が<br/>
`includes/post.php` と `includes/issue.php` に組み込まれています。<br/>
<br/>
ただし、srcset がない画像のCA検証では失敗するケースがあり、<br/>
現在は、srcset　がない場合は、<br/>
`ca-manager.php` の末尾で `wp_calculate_image_srcset` を無効化して対応しています。<br/>


### 4.10 Colibri / Swiper対応

CAマネージャー拡張版は、無料のWordPressテーマで当初実装しましたが、<br/>
有料テーマへのOP実装の難易度を図るために、<br/>
Colibri Builder へのCAマネージャー対応を行いました。<br/>
<br/>
無料テームで実装確認したものをColibriに定期要したところ、<br/>
特に大きな障壁はありませんでしたが、<br/>
画像が自動スライドするブロックで、画像CAが重複して発行され<br/>
CA検証が失敗するケースがありました。<br/>
そこで、<br/>
<br/>
`includes/issue.php` に Swiper duplicate slide 対応が追加されています。

- `swiper-slide-duplicate` による重複ID問題の軽減
- 重複した `op-body-*` IDの除去
- Colibriのようなビルダー由来DOM変形への対応

### 5 今後、改善すべき点 

#### 5.1 `error_log`処理

`front-assigned-ad.php` などに `error_log` が多数残っているため、以下の対策を行う予定です。

改善案:

- `Profile\Debug\debug()` に統一
- 本番ではログ抑制
- 管理画面のデバッグ設定と連動

#### 5.2 admin機能が肥大化

`includes/admin.php` が 3000行超になっており、
保守面で不安定となるため、以下の改善を考えています。

改善案:

- 設定画面
- 広告枠管理
- 広告申込管理
- 承認済広告管理
- 統計画面
- コンテキスト広告管理

上記をファイル分割した方が、保守性が大きく上がります。

#### 5.3 `issue.php` の肥大化

`includes/issue.php` が 3174行あり、<br/>
CA発行・本文ID付与・画像integrity・埋め込みCA・広告CA・一括発行・Colibri対応が<br/>
混在しているため、以下の改善を検討しています。<br/>
<br/>
改善案:

- `issue-article.php`
- `issue-embedded.php`
- `issue-ad.php`
- `target-normalizer.php`
- `integrity-image.php`
- `bulk-issue.php`
- `builder-compat.php`

のように分割するとよいです。

#### 5.3 実験機能と安定機能の境界を明確化

OPG共有ページ、コンテキスト広告、割当広告、広告申込DBなど、v0.4.3の延長として見ると<br/>
大幅な機能拡張であり、安定機能と実験機能の境界を明確化する必要があります。<br/>
<br/>
改善案:

READMEで以下に分類し、バージョンを分けることを考えています。

- 安定機能
- β機能
- 実験機能
- 今後の標準化提案候補

#### 5.5 DBアンインストール方針が未整理

独自テーブルを作るため、アンインストール時の扱いを整理する必要があります。

改善案:

- アンインストール時にDBを残す/削除する設定
- `uninstall.php` の追加検討
- バージョンアップ時のDB migration設計

#### 5.6 セキュリティレビューが必要

管理画面POST・広告申込・URLリダイレクト・HTML出力・ショートコードが増えているため、<br/>
第三者による以下の観点でのレビューが必要と考えています。<br/>

- nonce検証
- capabilityチェック
- SQL prepare
- esc_html / esc_attr / esc_url
- wp_kses_post の適用範囲
- リダイレクトURLの検証
- 広告HTMLの生出力可否

## 6. 改善・追加点の整理表

| 分類 | 公式CAマネージャー v0.4.3 | CAマネージャー拡張版 v0.4.8-x-share | 自己評価 |
|---|---|---|---|
| 記事CA | CSSセレクターと対象Integrity要素を指定し、管理画面のみで発行。個別画面対応できない。CSSセレクターを意識しないWP利用者にはハードルが高い。HTML実体文字などレンダリング時とCA発行時HTMLの差で発生する無害な改ざん検知まで拡張機能が検知してしまうため、OP実装が進まない。 | UI管理・一括発行・段落単位targetでCA発行が可能。HTML実体文字問題には正規化で対応、WPのレスポンシブ処理によるsrcsetに含まれる画像CAでの検証失敗に自動対応し、ユーザーがOP実装トラブルに遭遇しない。 | 大幅改善 |
| target設計 | 主に指定HTML/selector | 見出し/段落単位でop-body IDを自動発行 | 大幅改善 |
| 画像CA | 画像integrityは別途対応が必要。srcset対応も利用者まかせ。 | 複数integrity、img属性注入、埋め込みCA画像対応が可能。 | 改善。ただしsrcset無効化は要注意 |
| 埋め込みCA | なし | テキスト/画像の自己申告型CA | 新規追加 |
| 広告CA | なし | OnlineAd対応 | 新規追加 |
| 広告表示 | なし | top/middle/bottom、context/assigned | 新規追加 |
| 広告申込管理 | なし | DB・申込・承認・明細・ログ | 新規追加 |
| OPG拡張 | なし | og:op:* と共有ページ | 実験的追加 |
| Colibri対応 | 不明 | Swiper duplicate対策 | 実運用改善 |
| 管理UI | 設定画面中心 | CAマネージャー統合UI | 大幅改善 |
| 保守性 | シンプル | 機能増で肥大化 | 分割整理が必要 |

