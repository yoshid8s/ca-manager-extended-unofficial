# ca-manager-extension
本実装は、公式CA Manager（v0.4.3）をベースに独立して拡張を行ったものです

# CA Manager Extension (v0.4.3-1)

CA Manager（v0.4.3）をベースに、
WordPress上での実運用を想定して拡張した実装です。

## 主な機能
- 投稿単位でのCA管理
- 広告CA（OnlineAd）
- 埋め込みコンテンツCA

## 位置付け
本実装は、OPにおける「個別セレクター単位でのCA発行」という前提を、
CMS環境で実装可能にするためのリファレンス実装です。

# CA Manager Extension (v0.4.3-2)

バグ修正しました。更新履歴を参照ください。

---

## 主な機能

- 記事本文のCA発行（TextTargetIntegrity）
- 埋め込み画像のCA発行（ExternalResourceTargetIntegrity）
- 広告コンテンツのCA発行
- WordPress編集画面からCA管理
- CAS（application/cas+json）の自動埋め込み

---

## 更新履歴

### v0.4.3-2（2026-04）

#### バグ修正

**1. srcset画像のExternalResourceTargetIntegrity修正**
- `srcset` による複数ハッシュを分割していた問題を修正
- 各画像の `integrity` 属性を1つの値として扱うように変更

**2. 記事CAに画像が含まれない問題**
- main article CA に `external_resources` が渡されていなかった不具合を修正
- 埋め込み画像CAが無い場合、記事CAに画像整合情報が含まれるよう改善

**3. CA間の整合性修正**
- 以下の処理を統一：
  - 記事CA
  - 埋め込み画像CA
  - 広告CA
- 検証エラーの原因となる不整合を解消

---

## 画像Integrity処理について

本プラグインでは、`<img>`タグの `integrity` 属性に複数のハッシュ値が含まれる場合（例：srcset）でも、
それらを分割せず、1つのintegrity値として扱います。

- integrity値はそのまま1つの文字列として使用
- 個別ハッシュに分割しない
- 対象：
  - 記事CA
  - 埋め込み画像CA
  - 広告CA

これにより、検証の安定性を確保しています。

---

## インストール方法

### 方法①（開発者向け）

```bash
git clone https://github.com/yoshid8s/ca-manager-extension.git
WordPressの wp-content/plugins/ に配置してください。
```

### 方法②（手動）

リポジトリをダウンロード
フォルダをZIP化
WordPress管理画面からアップロード

### 使い方

- 投稿編集画面を開く。
- CAマネージャーで対象コンテンツを選択。
- 保存するとCASが自動生成される。

### 注意事項

srcsetを含む画像はブラウザ実DOMと一致する必要があります。
HTML構造の変更は検証エラーの原因になります。

### 作者

Yoshifumi Takeuchi
