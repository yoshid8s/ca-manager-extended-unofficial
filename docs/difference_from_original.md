For Japanese readers:
[Jump to Japanese version](#japanese)

# OP-CIP Official CA Manager v0.4.3 → Proprietary Extension Version Difference Summary Report

This document summarizes the main differences between the official CA Manager v0.4.3 provided by the Originator Profile Technology Research Association (OP-CIP) and the CA Manager extension version v0.4.8-X-share.

## Development Objectives

The CA Manager extension was developed to support the practical operation of websites using WordPress (WP) from the perspective of those who operate such websites.

After completing the above main objective, I attempted an experimental implementation of OP contextual advertising using OP metadata.

OP contextual advertising is achieved by linking the `genre` property of article CAs and ad CAs, resulting in the following value: <br/>

- Visitors to a webpage can see the advertisements offered there as part of the web information relevant to the article.
- As a result, attention to the advertisements increases, improving ad awareness and click-through rates.
- Because the advertisements do not interfere with the article, the rate of in-depth reading of the article itself improves.

In fact, the average click-through rate of the 12 advertisements placed on my own blog [JiJi Style](https://style.yh-inc.jp/) where contextual advertising is implemented is 19.2% (results after approximately one month of implementation).

<br/>
After completing the above development, I confirmed that the sender information of the OP attached to my website<br/>
functions effectively even on third-party platforms by extending the web standard technology OGP<br/>
by posting some blocks of web pages to X.

To confirm this, I separately developed a browser extension [OGP CA Extension](https://github.com/yoshid8s/ogp-ca-extension/tree/main).

## Positioning of this Report

This report summarizes the differences between the official CA Manager (v0.4.3) provided by the Originator Profile Technology Research Association (OP-CIP) and an unofficial extended version of the CA Manager that we have independently extended.

## Background of Development

I developed this independently extended version of the CA Manager because, from the perspective of a WordPress user, the official version was difficult to use.

For example:
- It lacks the necessary consideration for "harmless tampering detection," which the provider should have addressed, resulting in frequent errors in the OP extension.
- CA issuance is not possible on a per-edit page basis.
- Batch CA issuance is not possible when there are many pages.

And so on.

If the official CA Manager is not user-friendly from a user's perspective,
I believe that the number of websites implementing the OP will not increase, no matter how socially relevant the OP is.
I hope that OP-CIP will refer to this report and implement user-friendly updates to the official CA Manager. <br/>

## 1. Custom Extended Version Targeted in the Diff Report

The version targeted in this report's diff report is [v0.4.8-x-share](https://github.com/yoshid8s/ca-manager-extended-unofficial/releases).

## 2. Summary of File Structure Differences

### Added Files

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

### Changed Files

- `README.md`
- `ca-manager.php`
- `includes/activator.php`
- `includes/admin.php`
- `includes/class-uca.php`
- `includes/issue.php`
- `includes/post.php`

## 3. Scale of Changes

The official version v0.4.3 was a relatively simple article CA issuance plugin, but the custom extension version has significantly expanded the following files in particular:

- `includes/issue.php`: 319 lines → 3174 lines
- `includes/admin.php`: 315 lines → 3013 lines
- `includes/post.php`: 101 lines → 434 lines
- `includes/class-uca.php`: 106 lines → 218 lines

The reason for this significant expansion is:

It has been expanded from an article CA issuance plugin to an integrated CA management plugin that includes article CA, ad CA, embedded content CA, and ad delivery/application management.

## 4. Main Added Features

### 4.1 CA Manager Editing UI

The `includes/admin-ui.php` file adds a custom UI (see example below) for managing CAs in the post and page editing screens.

<img width="1150" height="625" alt="image" src="https://github.com/user-attachments/assets/60562252-5c81-4065-ac20-1b96f5fe0e9b" />

The newly expanded UI provides centralized management of the following:

- Article CAs
- Advertisement CAs
- Embedded Content CAs
- Selector Management via Text Selection
- CA Target Registration via Image Selection
- Meta Information such as Editor, Author, Rights Holder, and Source URL

While the official v0.4.3 allowed for bulk CA issuance for pages with a specified CSS selector,

it was not possible to register CA properties such as editor, author, and genre. <br/>Furthermore, because there is no CA issuance function on individual editing pages, in media where the editor or author differs from page to page,<br/>in addition to the CA manager, the user had to<br/> independently add functionality to the CMS.

### 4.2 Stabilizing Article CA

The process of assigning `op-body-*` IDs to body elements in `includes/issue.php` has been significantly expanded.

Main Improvements:

- Targeting p / h1-h6 / blockquote / figcaption / pre, etc.
- Generating stable IDs based on SHA1 after text normalization
- Splitting `TextTargetIntegrity` targets into paragraph and heading units
- Merge processing with existing CAs
- Batch CA issuance processing for articles
- Detection of warning reasons
- Temporary saving and restoration of meta information

In particular, the ability for non-engineer CMS users who have never been aware of the CSS selectors used in HTML to automatically issue CAs by breaking them down into paragraph units, which is the original function of CA, is considered a very important improvement for practical WordPress operation.

### 4.3 Embedded Content CA

"Embedded Content CA" is a "self-declaration" by the article's publisher that the content originates from a third party. <br/>

<img width="1149" height="717" alt="image" src="https://github.com/user-attachments/assets/7e7ae15f-6f85-4c8c-a936-cea1a9b1d775" />

<br/><br/><br/>
I have independently extended the concept of "embedded content CA," which is a general term for issuing CAs for quotations such as text and photos created by third parties other than the original author that are included in an article.

Please note that this is a suggestion from me to OP-CIP and does not officially exist.

<br/>
Ideally, if there is third-party content, that entity should have an OP/CA and embed it in the article. <br/>
However, given that not all website operators have OP/CA, and OP is still in its early stages of adoption,<br/>
when an article contains third-party content, it is considered a realistic approach for the article's publisher to "self-declare" that it is not their own content,<br/>
and belongs to a third party. <br/>
<br/>
I would appreciate it if OP-CIP could discuss the concept of "embedded content CA" within OP-CIP and<br/>
show me the direction that should be taken. <br/>

The CA Manager extension adds a mechanism in the following file<br/>
to treat quoted text and embedded images in the main body as independent CA targets. <br/>

- `includes/admin-embedded-content.php`
- `includes/admin-ui.php`
- `includes/issue.php`

Main additions:

- Management of multiple embedded items using `_cam_embedded_items`
- Text CA: `TextTargetIntegrity`
- Image CA: `ExternalResourceTargetIntegrity`
- Support for subject_type `Image`
- Retention of rights holder, source URL, and notes
- Processing to exclude embedded items from text CA

This design separates text citations by third parties and externally sourced images as "self-declared CAs".

### 4.4 Online Ad / Ad CA Support

This Ad CA also follows the same concept as "Embedded Content CA,"<br/>
It is designed with the idea of ​​"self-declaring" that ad images created by third parties (advertisers) are advertisements from advertisers whose existence has been verified by the article publisher.
<br/>
<br/>
`subject_type` has been added to `includes/class-uca.php`,<br/>
It can now handle not only `Article` but also `Online Ad` and `Image`.
<br/>
<br/>
Online Ad has been extended to the following structure:

- `credentialSubject.type: OnlineAd`
- Use `name` instead of `headline`
- Add `landingPageUrl`
- Add `ExternalResourceTargetIntegrity` to the ad image
- Have the ad image selector and integrity as CA targets

As mentioned in "Embedded Content CA," this is a realistic solution given that not all advertisers placing ads on OP-implemented media have implemented OP themselves.

From the perspective of advertising sales, it is not realistic for OP-implemented media or advertising agencies to force advertisers to implement OP on their websites or ad data when proposing advertising on OP-implemented media.

This is something I can confidently say based on my own experience as an advertising professional for over 30 years.

As a realistic solution until OP becomes widespread, I hope OP-CIP will consider this idea.

### 4.5 Ad Application, Approval, and Delivery Management DB

To extend CA Manager to ad delivery/ad application workflows,<br/>
`includes/class-cam-ad-db.php` is used to create a custom table when the plugin is activated. <br/>
<br/><br/><br/>
<img width="862" height="631" alt="image" src="https://github.com/user-attachments/assets/fefb80af-6fb8-4050-83e7-213e3dd7f618" />
<br/><br/><br/>
Main tables created:

- `cam_advertisers`: Advertisers
- `cam_ad_slots`: Ad slots
- `cam_ad_applications`: Ad applications
- `cam_ad_application_items`: Ad application details
- `cam_ad_reviews`: Review history
- `cam_ad_delivery_logs`: Delivery logs
- `cam_ad_daily_stats`: Daily summaries

### 4.6 Contextual Ads

A mechanism that displays ads using matching article genres, body text, and titles is built into `includes/context-ads.php` and `includes/front-ad-insert.php`.

<br/><br/><br/>

<img width="874" height="724" alt="image" src="https://github.com/user-attachments/assets/9aaf5e68-2934-4a38-a1f4-8e40ca10c2a2" />

<br/><br/><br/>
Place the following shortcode in three locations on the article page: top, middle, and bottom.
Once the genre settings for the article CA and the genre settings for ad application are configured,
ads that match the conditions will be displayed as a result of competitive bidding from multiple advertisers, including the applied ad price. <br/>
<br/>
Additional shortcodes:

- `[cam_ad_top]`
- `[cam_context_ad_top]`
- `[cam_context_ad_middle]`
- `[cam_context_ad_bottom]`

Furthermore, logging of clicks, impressions, bottom reach, time reach, etc. has been added.

### 4.7 Assigned Ads

A mechanism to assign and display ad applications to specific articles has been added by<br/>
`includes/front-assigned-ad.php`. <br/>
<br/><br/><br/>

<img width="1150" height="718" alt="image" src="https://github.com/user-attachments/assets/99217619-83b7-46f5-a2fe-a6f3bebbed68" />

<br/><br/><br/>
This takes precedence over the contextual ads above. <br/>
For example, articles about Shohei Ohtani.<br/>
This feature is for advertisers who want to exclusively target specific articles. <br/>
<br/>
Additional shortcodes:

- `[cam_assigned_ad_top]`
- `[cam_assigned_ad_middle]`
- `[cam_assigned_ad_bottom]`

This model assigns ad application IDs per article, rather than using contextual matching.

### 4.8 OGP / OPG Extension

This is an experimental feature that takes X sharing and OGP extensions into consideration.<br/>
The following has been added to `includes/post.php`: <br/>
<br/><br/><br/>

<img width="906" height="710" alt="image" src="https://github.com/user-attachments/assets/55670cae-aa29-41f1-9c7a-0e1d779e41e8" />

<br/><br/><br/>
This feature embeds OGP metadata, which has been specially extended for OPs, into the HTML. <br/>

- `og:op:type`
- `og:op:cas`
- Generating shared pages in the format `/op-share/{hash}`
- OGP meta output for `TextBlockAttestation`

### 4.9 Extended Image Integrity Processing

To smoothly process CA issuance for WordPress's responsive image optimization process,<br/>
The `integrity` attribute is added to images, and processing to handle multiple hashes from the srcset image information is<br/>
This is built into `includes/post.php` and `includes/issue.php`. <br/>
<br/>
However, CA verification for images without a srcset may fail in some cases,<br/>
Currently, if there is no srcset,<br/>
`wp_calculate_image_srcset` is disabled at the end of `ca-manager.php` to address this. <br/>

### 4.10 Colibri / Swiper Support

The CA Manager extension was initially implemented in a free WordPress theme, but<br/>
To gauge the difficulty of implementing OP in paid themes,<br/>
CA Manager support was added to Colibri Builder.

<br/>
When I installed the implementation confirmed in the free theme to Colibri,<br/>
there were no major obstacles,<br/>
however, in blocks where images automatically slide, duplicate image CAs were issued,<br/>
and CA verification failed in some cases.

Therefore,<br/>
<br/>
Swiper duplicate slide support has been added to `includes/issue.php`.

- Mitigation of duplicate ID issues with `swiper-slide-duplicate`
- Removal of duplicate `op-body-*` IDs
- Support for builder-derived DOM transformations like Colibri

### 5. Areas for Future Improvement

#### 5.1 `error_log` Handling

Since many `error_log` entries remain in `front-assigned-ad.php` and other files, we plan to implement the following measures:

Proposed Improvements:

- Unified to `Profile\Debug\debug()`
- Suppress logs in production
- Link with debug settings in the admin panel

#### 5.2 Admin Functionality Has Become Bloated

`includes/admin.php` has exceeded 3000 lines,
making it unstable from a maintenance perspective. Therefore, we are considering the following improvements:

Proposed Improvements:

- Settings Screen
- Ad Slot Management
- Ad Application Management
- Approved Ad Management
- Statistics Screen
- Contextual Ad Management

Splitting the above into separate files would significantly improve maintainability.

#### 5.3 `issue.php` Bloat

`includes/issue.php` has 3174 lines and contains a mix of CA issuance, body ID assignment, image integrity, embedded CA, ad CA, bulk issuance, and Colibri support.
I am considering the following improvements:

<br/>
Suggested improvements:

- `issue-article.php`
- `issue-embedded.php`
- `issue-ad.php`
- `target-normalizer.php`
- `integrity-image.php`
- `bulk-issue.php`
- `builder-compat.php`

Splitting the above into separate files will significantly improve maintainability.

#### 5.3 Clarifying the Boundary Between Experimental and Stable Features

Considering features such as the OGP sharing page, contextual ads, assigned ads, and ad application DB as an extension of v0.4.3,
<br/>
these represent significant feature expansions, and it is necessary to clarify the boundary between stable and experimental features.
<br/>
<br/>
Suggested Improvements:

I am considering classifying features as follows in the README and separating versions accordingly:

- Stable Features
- Beta Features
- Experimental Features
- Potential Standardization Proposals

#### 5.5 Unorganized DB Uninstallation Policy

Since I am creating custom tables, the handling of uninstallation needs to be clarified.

Suggested Improvements:

- Setting to retain/delete the DB during uninstallation
- Consideration of adding `uninstall.php`
- DB migration design during version upgrades

#### 5.6 Security Review Needed

Due to the increase in admin panel POST, ad application, URL redirect, HTML output, and shortcodes,<br/>
I believe a review by a third party from the following perspectives is necessary. <br/>

- nonce verification
- capability check
- SQL prepare
- esc_html / esc_attr / esc_url
- Scope of application of wp_kses_post
- Redirect URL verification
- Raw output of ad HTML possible/not possible

## 6. Summary table of improvements and additions

| Classification | Official CA Manager v0.4.3 | CA Manager Extended Version v0.4.8-x-share | Self-evaluation |
|---|---|---|---|
| Article CA | Issued only from the administration screen by specifying CSS selectors and target Integrity elements. Individual screen support is not possible. It is a high hurdle for WP users who are not aware of CSS selectors. The extension detects even harmless tampering caused by differences between HTML rendering and CA issuance (such as HTML actual characters), hindering OP implementation. | CA issuance is possible with UI management, bulk issuance, and paragraph-level targeting. HTML character encoding issues are addressed through normalization, and validation failures in image CAs included in srcsets due to WP's responsive design are automatically handled, preventing users from encountering OP implementation problems. | Significant Improvement |
| Target Design | Primarily specified HTML/selector | Automatically generates op-body IDs for each heading/paragraph | Significant Improvement |
| Image CA | Image integrity requires separate handling. srcset support is left to the user. | Supports multiple integrity, img attribute injection, and embedded CA images. | Improvement. However, disabling srcset requires caution |
| Embedded CA | None | Self-declared text/image CA | Newly added |
| Ad CA | None | Online Ad support | Newly added |
| Ad display | None | top/middle/bottom, context/assigned | Newly added |
| Ad application management | None | DB, application, approval, details, log | Newly added |
| OGP extension | None | og:op:* and shared pages | Experimental addition |
| Colibri support | Unknown | Swiper duplicate prevention | Improved for actual operation |
| Management UI | Settings screen-centric | CA manager integrated UI | Significantly improved |
| Maintainability | Simple | Became bloated with increased functionality | Needs to be split and organized |


<a id="japanese"></a>

# Japanese Version

# OP-CIP公式CA Manager v0.4.3 → 独自拡張版 差分整理レポート

このドキュメントでは、Originator Profile技術研究組合（OP-CIP）が提供する<br/>
公式CA Manager v0.4.3と、CAマネージャー拡張版v0.4.8-X-share の主な違いをまとめています。<br/>

## 開発目的

CAマネージャー拡張版は、WordPress（WP）を利用し、Webサイトを運営する側の視点で<br/>
その実用的運用をサポートするために開発されました。<br/>
<br/>
上記の主目的完了後に、<br/>
OPメタデータを利用したOPコンテキスト広告の実験的実装を試みました。<br/>
<br/>
OPコンテキスト広告は、記事CAと広告CAのプロパティである `genre` を<br/>
リンクさせることで実現するものですが、以下の価値が生まれます。<br/>

- Webページへの訪問者に、そこで提供される広告も記事に関連したWeb情報の一部として提供することができる
- その結果、広告への注目度が高まり、広告認知率、クリック率が向上します
- 広告が記事の邪魔者にならないため、記事そのものの精読率が向上します

実際に、コンテキスト広告を実装した自身のブログ [JiJi Style](https://style.yh-inc.jp/) に掲出された12の広告の<br/>
平均クリック率は19.2％です（実装後、約1ヶ月の結果）<br/>
<br/>
上記の開発完了後に、自分のWebサイトに付与したOPの発信者情報が<br/>
第三者のプラットフォーム上でも、Webスタンダード技術OGPを拡張することで<br/>
有効に機能することを、XへのWebページの一部のブロックの投稿で確認しました。<br/>

その確認のために、ブラウザ拡張機能[OGP CA拡張機能](https://github.com/yoshid8s/ogp-ca-extension/tree/main)を別途開発しました。

## 本レポートの位置付け

本レポートは、Originator Profile技術研究組合（OP-CIP）提供の<br/>
公式CAマネージャー(v0.4.3)をもとに、独自拡張したCAマネージャー拡張版（非公式）の差分を整理したものです。

## 開発の背景

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
これは、私自身の30年を超える広告マンとしての経験から断言できることです。<br/>
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

