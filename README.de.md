# Paymos Stablecoin-Spenden für GiveWP

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | **Deutsch** | [Türkçe](README.tr.md)

Akzeptieren Sie einmalige Stablecoin-Spenden über den gehosteten Paymos-Checkout in GiveWP. Paymos ergänzt sowohl visuelle (Visual) als auch klassische (Legacy) Spendenformulare in GiveWP um eine Stablecoin-Zahlungsmethode. Spender öffnen den Paymos-Checkout über eine Statusseite auf Ihrer Website, und GiveWP verbucht eine bestätigte Spende samt nativer Spendenquittung erst, nachdem das Plugin die Zahlung bei Paymos verifiziert hat.

[![Video ansehen: 12 offizielle CMS-Krypto-Plugins — 1-Klick-Verbindung (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)
> **Video-Rundgang (1:12, Untertitel in 6 Sprachen):** [Auf YouTube ansehen](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Überblick

Paymos Stablecoin Donations bindet den gehosteten Paymos-Checkout direkt in GiveWP für einmalige Spenden über Visual- und Legacy-Spendenformulare ein.

- Spender starten den Paymos-Checkout über eine eigene Statusseite auf Ihrer WordPress-Website.
- Die Zahlungsbestätigung verlässt sich niemals allein auf eine Browser-Weiterleitung — GiveWP erfasst eine bestätigte Spende und stellt die native Quittung erst bereit, nachdem signierte Webhook-Benachrichtigungen und eine Prüfung über die Merchant API die Zahlung bestätigt haben.
- Unterstützt vollständige Rückerstattungen sowie Überzahlungs-Rückerstattungen direkt aus dem WordPress-Adminbereich.
- Lizenziert unter GPLv2 oder höher ([LICENSE](LICENSE)). Aktuelle stabile Version: `1.0.1`.

## Voraussetzungen

- WordPress `6.9` oder neuer (getestet bis `7.1`)
- GiveWP `4.19.1.1` oder neuer
- PHP `7.4` oder neuer
- Eine HTTPS-WordPress-Website
- Ein Paymos-Konto und -Projekt
- Aktivieren Sie das Plugin auf jeder Website einzeln (unterstützt einmalige Spenden)

## Installation & Verbindung

1. Installieren und aktivieren Sie GiveWP, und installieren und aktivieren Sie anschließend **Paymos Stablecoin Donations** (über das WordPress.org-Plugin-Verzeichnis oder [Releases](https://github.com/Paymos-labs/givewp/releases)).
2. Öffnen Sie **GiveWP → Settings → Payment Gateways → Paymos**. Wählen Sie **Sandbox** oder **Live**, passend zum Zahlungsmodus von GiveWP.
3. Klicken Sie auf **Connect Paymos** und bestätigen Sie Website und Projekt auf der Paymos-Freigabeseite.
4. Aktivieren Sie Paymos in den Visual- oder Legacy-Formularen, über die Sie Stablecoins annehmen möchten.

## Externe Dienste & Sicherheit

Paymos stellt den gehosteten Checkout und die Merchant API bereit, die dieses Gateway nutzt. Lesen Sie die [Paymos-Nutzungsbedingungen](https://paymos.io/de/terms) und die [Datenschutzerklärung](https://paymos.io/de/privacy).

**Verbindung.** Wenn ein Administrator die Verbindung startet, sendet WordPress `client_id=paymos-cms` und `scope=cms:bootstrap` an `https://app.paymos.io/connect/cms/device`. Der Browser-Freigabelink enthält die Plugin-Kennung `givewp`, die Website-URL, einen Benutzercode sowie die Admin-Rückkehr-URL, sofern sie denselben Origin wie die Website hat. Während der Freigabe fragt WordPress `https://app.paymos.io/connect/token` per Device-Code-Grant mit `client_id` und dem geheimen `device_code` ab. WordPress speichert die Zugangsdaten der gewählten Umgebung in einer verschlüsselten Option und verwirft das kurzlebige OAuth-Token.

**Spenden.** Wenn ein Spender den Paymos-Checkout öffnet, sendet WordPress `POST /v1/invoices` an `https://api.paymos.io` mit `project_id`, `amount`, `currency` und einer zufälligen `external_order_id`. Spenderdaten und GiveWP-Quittungsdaten bleiben in WordPress. Das Plugin prüft die konkrete Rechnung über `GET /v1/invoices/{invoice_id}` und kann unterbrochene Anfragen über `GET /v1/invoices` anhand des Projekts und der ursprünglichen Referenz nachschlagen. Diese Anfragen tragen eine Key-ID, einen Zeitstempel und eine HMAC-Signatur. Das Signatur-Secret verlässt Ihre Website nicht.

**Zahlung und Bestätigung.** Spender öffnen die gehostete Rechnung unter `https://checkout.paymos.io`. Paymos sendet signierte Rechnungsbenachrichtigungen an den WordPress-Webhook des verbundenen Paymos-Projekts. Das Plugin prüft die Signatur, ruft die genaue Rechnung über die API ab und verifiziert Geltungsbereich und Zahlung, bevor GiveWP aktualisiert wird. Zusätzlich prüft der geplante WordPress-Abgleichsjob offene Vorgänge über die API.

## Rückerstattungen

Berechtigte Mitarbeiter können unter **GiveWP → Paymos refunds** eine vollständige Rückerstattung oder eine Überzahlungs-Rückerstattung anfordern. WordPress sendet eine signierte Anfrage `POST /v1/refunds` mit `invoice_id`, `mode` und einer zufälligen `external_order_id`. Paymos ermittelt das Ziel anhand der ursprünglichen Zahlung.

Das Plugin prüft den Vorgang über `GET /v1/refunds/{withdrawal_id}` und bei Bedarf über die rechnungsbezogene Liste `GET /v1/refunds`. GiveWP verbucht eine vollständige Rückerstattung erst nach Bestätigung des abgeschlossenen Paymos-Vorgangs. Eine Rückerstattung des überzahlten Betrags behält den ursprünglichen Spendenstatus bei.

## Häufig gestellte Fragen

**Wie kann ein Spender eine Spende nach dem Neuladen der Seite fortsetzen?**

Sofern der Browser den Wiederherstellungslink speichern kann, kann der Spender im selben Formular **Resume your previous Paymos donation** wählen. Der Link öffnet die Statusseite Ihrer Website und läuft innerhalb von 12 Stunden ab. Die Website prüft den ursprünglichen Browser und die GiveWP-Zuordnung, bevor Status oder Quittung angezeigt werden. Ein reguläres neues Absenden startet eine separate Spende.

**Was passiert beim Trennen oder Löschen des Plugins?**

Das Deaktivieren des Gateways stoppt neue Paymos-Spenden, behält jedoch die Zugangsdaten zur Prüfung noch offener Vorgänge bei. Das Deaktivieren des Plugins beendet seine geplanten Prüfungen. Beim Löschen werden lokale Zugangsdaten, der Browser-Zugriff auf Statusseiten und geplante Aufgaben entfernt. GiveWP behält seine Spendenhistorie. Das Plugin bewahrt minimale Referenzen für ungeklärte Zahlungen und Rückerstattungen auf, damit ein Administrator sie manuell prüfen kann. Entfernte Schlüssel und Webhooks verwalten Sie im Paymos-Projekt.

## Support

- Dokumentation: [paymos.io/de/docs/cms-givewp](https://paymos.io/de/docs/cms-givewp)
- Quellcode und Releases: [Paymos-labs/givewp](https://github.com/Paymos-labs/givewp)
- Support: Wenden Sie sich für Hilfe zu Paymos-Checkout, Verbindung oder Rückerstattungen an [support@paymos.io](mailto:support@paymos.io).
- Lizenz: GPLv2 oder höher ([LICENSE](LICENSE))
