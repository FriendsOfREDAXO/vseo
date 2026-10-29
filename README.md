# vSEO – SEO-Monitoring für REDAXO

Überwacht die Sichtbarkeit eigener Websites in Google – direkt im REDAXO-Backend.

Im Mittelpunkt steht die **Anzahl rankender Suchbegriffe und URLs**, nicht nur Klicks. Ein URL-Umbau ohne Weiterleitungen kann einen großen Teil der rankenden Suchbegriffe kosten, während Klicks und Sichtbarkeitsindizes kaum reagieren. vSEO macht solche Fälle innerhalb einer Woche sichtbar und warnt von selbst.

*English summary below.*

## Funktionen

- **Übersicht** aller Domains: rankende Suchbegriffe, rankende URLs, Klicks und Impressionen der letzten 28 Tage im Vergleich zum Vorzeitraum, Mini-Verlauf, Ampel für Warnungen.
- **Verlauf** je Woche über 3, 6, 12 Monate oder alles, mit einblendbaren Google-Updates (Liste im Backend pflegbar).
- **Gewinner/Verlierer**: zwei frei wählbare Zeiträume, je Suchbegriff oder URL, Filter nach Gerät, Land und Text, CSV-Export.
- **Verlorene URLs**: URLs mit Impressionen im Vorzeitraum und (fast) keinen jetzt – daneben der aktuelle Statuscode aus dem Crawler.
- **Technik**: robots.txt, Sitemaps (eigener Abruf und Search Console), Indexstatus einer Stichprobe (URL Inspection API) und eine Problemliste mit Verlauf: seit wann, zuletzt gesehen, behoben.
- **Crawler**: Statuscode, Weiterleitungsketten, canonical, noindex/X-Robots-Tag, Sperren in der robots.txt, Title und Description (Länge, Duplikate), hreflang.
- **Matomo** (optional): Besuche aus Suchmaschinen, Einstiegsseiten aus der Suche mit Umsatz, 404-Seiten mit Verlauf.
- **Warnungen** mit einstellbaren Regeln, Mail sofort oder als Wochenbericht, Verlauf mit „gesehen“.
- **Dashboard-Widget** für das AddOn [dashboard](https://github.com/FriendsOfREDAXO/dashboard), falls installiert.

Das AddOn ist unabhängig: Zugänge zur Search Console und zu Matomo werden im AddOn selbst gepflegt. YRewrite, Cronjob, PHPMailer und Dashboard werden genutzt, wenn sie vorhanden sind.

## Voraussetzungen

- REDAXO ≥ 5.16, PHP ≥ 8.1 mit den Erweiterungen openssl, sodium, json, dom und xmlreader
- Empfohlen: AddOn **Cronjob** (regelmäßige Läufe) und **PHPMailer** (Warnungen per Mail)
- Optional: **YRewrite** (Domain-Vorschläge), **dashboard** (Widget)
- Ausgehende HTTPS-Verbindungen zu `googleapis.com` und ggf. zur eigenen Matomo-Instanz

## Einrichtung Schritt für Schritt

### 1. Google-Cloud-Projekt und Search Console API

1. In der [Google Cloud Console](https://console.cloud.google.com/) ein Projekt anlegen oder ein vorhandenes wählen.
2. *APIs & Dienste → Bibliothek*: **Google Search Console API** suchen und aktivieren.

### 2. Dienstkonto anlegen

1. *IAM & Verwaltung → Dienstkonten → Dienstkonto erstellen*, Name z. B. `seo-monitor`. Rollen im Projekt sind nicht nötig.
2. Das Dienstkonto öffnen, *Schlüssel → Schlüssel hinzufügen → Neuen Schlüssel erstellen → JSON*. Die Datei wird heruntergeladen.
3. Die E-Mail-Adresse des Dienstkontos notieren (`…@….iam.gserviceaccount.com`).

> Verbietet die Organisation das Erstellen von Schlüsseln (Richtlinie `iam.disableServiceAccountKeyCreation`), muss ein Organisations-Admin eine Ausnahme für das Projekt einrichten.

### 3. Dienstkonto in der Search Console berechtigen

Für jede Property in der [Search Console](https://search.google.com/search-console): *Einstellungen → Nutzer und Berechtigungen → Nutzer hinzufügen*, die E-Mail des Dienstkontos eintragen. Die Berechtigung **Eingeschränkt** genügt, auch für die URL Inspection. Nutzer hinzufügen können nur Inhaber einer Property.

### 4. Zugang im Backend hinterlegen

*vSEO → Einstellungen → Zugänge → Search-Console-Zugang hinzufügen*: Inhalt der JSON-Datei einfügen oder die Datei hochladen. Der Schlüssel wird verschlüsselt gespeichert und nie wieder angezeigt. Danach *Verbindung testen*: Es erscheint die Liste der Properties.

Die heruntergeladene JSON-Datei anschließend sicher ablegen oder löschen; sie gehört nicht auf den Webserver.

### 5. Domains anlegen

*Einstellungen → Domains*: Das AddOn schlägt YRewrite-Domains mit passender Property vor sowie Properties, zu denen es noch keine Domain gibt (z. B. externe Shops). Vorschläge auswählen und übernehmen oder Domains von Hand anlegen. Domain-Properties (`sc-domain:example.com`) und URL-Präfix-Properties (`https://www.example.com/`) werden unterstützt.

Je Domain lassen sich außerdem eigene Sitemap-Pfade, Empfänger für Warnungen und die Matomo-Website eintragen.

### 6. Matomo (optional)

1. In Matomo unter *Persönlich → Sicherheit → Auth-Token* ein Token anlegen. Ein Nutzer mit **Lesezugriff** auf die Websites genügt.
2. *Einstellungen → Zugänge → Matomo-Zugang hinzufügen*: Matomo-URL und Token eintragen. Das Token wird verschlüsselt gespeichert und nur per POST übertragen.
3. Bei der Domain die Matomo-Website wählen.

Einstiegsseiten aus der Suche nutzen das Segment `referrerType==search`. Ist in Matomo die Archivierung über den Browser abgeschaltet, muss dieses Segment vorab archiviert werden (Segment anlegen, „vorverarbeitet“).

404-Seiten werden über ein einstellbares Muster für Seitentitel oder URL erkannt (*Einstellungen → Import und Crawler*). Aussagekräftig wird das, wenn das 404-Tracking die aufgerufene URL im Titel mitgibt, z. B. `404/URL = /pfad/From = …`; ein reiner Titel wie „404 – Seite nicht gefunden“ liefert nur die Gesamtzahl.

### 7. Cronjobs

Im AddOn *Cronjob* anlegen:

| Typ | Empfehlung |
| --- | --- |
| vSEO: Import | stündlich, solange der Backfill läuft (bis zu 16 Monate), danach täglich |
| vSEO: Crawler | stündlich oder mehrmals täglich |
| vSEO: Warnungen | täglich nach Import und Crawler |

Jeder Lauf arbeitet innerhalb eines Zeitbudgets und setzt beim nächsten Mal fort; eine Sperre verhindert parallele Läufe. Der Import umfasst Search Analytics, Matomo und die URL-Inspection-Stichprobe.

### 8. Warnungen

*Einstellungen → Warnregeln*: Regeln aktivieren, Schwellen anpassen und festlegen, welche sofort per Mail gehen. Allgemeine Empfänger und den Tag für den Wochenbericht eintragen und mit *Testmail senden* prüfen (PHPMailer muss eingerichtet sein).

| Regel | Standard |
| --- | --- |
| Rankende Suchbegriffe / URLs gesunken | −20 % gegenüber dem Schnitt der vier Wochen davor, sofort per Mail |
| Klicks gesunken | −30 % |
| 404 aus Suchmaschinen gestiegen (Matomo) | Faktor 3, sofort per Mail |
| Neue Fehlerseiten (Crawler) | ab 5 in 7 Tagen, sofort per Mail |
| robots.txt/Sitemap-Fehler | sofort per Mail |
| Indexierter Anteil der Stichprobe gesunken | −10 Prozentpunkte |
| Neue Weiterleitungsketten | ab 1 in 7 Tagen |
| Keine aktuellen Daten | älter als 7 Tage oder Importfehler |

## Konsole

```bash
php redaxo/bin/console vseo:import [--domain=ID|host] [--source=gsc|matomo|gsc_inspect] [--budget=Sekunden]
php redaxo/bin/console vseo:backfill --domain=www.example.com --from=2025-01-01 [--to=2025-06-30]
php redaxo/bin/console vseo:aggregate [--domain=…]
php redaxo/bin/console vseo:crawl [--domain=…] [--limit=URLs] [--refresh] [--budget=Sekunden]
php redaxo/bin/console vseo:alerts [--domain=…] [--no-mail] [--digest]
```

## Rechte

- `vseo[]`: vSEO ansehen
- `vseo[settings]`: Einstellungen, Zugänge, Domains, Warnregeln
- `vseo[all_domains]`: alle Domains sehen
- `vseo[domain_ID]`: nur bestimmte Domains sehen. Wer keines dieser Domain-Rechte hat, sieht alle Domains.

## Daten und Aufbewahrung

Suchbegriffe und URLs liegen in eigenen Wörterbuch-Tabellen und werden per ID referenziert. Die Detaildaten (Tag × Suchbegriff × URL × Gerät × Land) werden nach der eingestellten Dauer gelöscht (Standard 25 Monate); Tages- und Wochensummen bleiben unbegrenzt. Ein Shop mit einigen tausend URLs kommt für 16 Monate leicht auf eine Million Detailzeilen.

## Datenschutz und Sicherheit

- Zugangsdaten werden mit libsodium verschlüsselt in der Datenbank gespeichert, nie im Klartext angezeigt und nicht ins Log geschrieben.
- Der Schlüssel dafür liegt in `redaxo/data/addons/vseo/secret.key` (wie das ganze `data`-Verzeichnis per `.htaccess` gesperrt) oder – besser – in der Umgebungsvariablen `VSEO_KEY` (32 Byte, base64). Geht der Schlüssel verloren, müssen die Zugänge neu eingetragen werden; bei einem Umzug also mitnehmen.
- Alle Formulare sind per CSRF-Token geschützt.
- Externe Aufrufe laufen mit Timeout; Fehler landen im System-Log (`[vseo]`).
- Der Crawler ruft nur URLs der eingetragenen Domains auf, mit Pause zwischen den Abrufen und dem User-Agent `vSEO`.

## Erweitern

### Eigene URLs für den Crawler (`VSEO_URL_SOURCE`)

Zum Beispiel Seiten eines Shops außerhalb der REDAXO-Struktur:

```php
rex_extension::register('VSEO_URL_SOURCE', static function (rex_extension_point $ep) {
    /** @var \Viewsion\SeoMonitor\Model\Domain $domain */
    $domain = $ep->getParam('domain');
    $urls = $ep->getSubject();
    foreach (my_shop_product_urls($domain->host) as $url) {
        $urls[] = $url;
    }
    return $urls;
});
```

### Eigene Warnungen (`VSEO_ALERT`)

```php
rex_extension::register('VSEO_ALERT', static function (rex_extension_point $ep) {
    $alerts = $ep->getSubject();
    if (my_check_failed($ep->getParam('domain'))) {
        $alerts[] = [
            'rule' => 'my_check',      // erscheint als ext_my_check
            'ref' => date('Y-m-d'),    // je Anlass nur eine Warnung
            'severity' => 3,           // 1 Hinweis, 2 Warnung, 3 kritisch (sofort per Mail)
            'message' => 'Beschreibung',
        ];
    }
    return $alerts;
});
```

### Weitere Datenquellen (`VSEO_DATA_SOURCES`)

Eine Klasse, die `Viewsion\SeoMonitor\Source\DataSourceInterface` implementiert, an die Liste anhängen; der Import ruft sie je Domain mit Zeitbudget auf.

### Rank-Tracking (`VSEO_RANK_TRACKERS`)

Vorgesehen für SERP-Dienste wie DataForSEO: eine feste Keyword-Liste je Domain wöchentlich samt Wettbewerbern abfragen. Version 1 enthält nur die Schnittstelle `Viewsion\SeoMonitor\Source\Rank\RankTrackerInterface`, das Ergebnisobjekt `RankResult` und die Tabellen `vseo_rank_keyword` und `vseo_rank`; eine Implementierung liefert ein eigenes AddOn über den Extension Point.

## Häufige Fragen

**Die Zahlen weichen von der Search Console ab.** Klicks und Impressionen stammen aus der Abfrage ohne Suchbegriff/URL und entsprechen der Search Console. Die Zahl rankender Suchbegriffe kommt aus den Detaildaten; Google lässt dort anonymisierte Suchbegriffe weg.

**Die neuesten Tage fehlen.** Google liefert mit zwei bis drei Tagen Verzug. Jeder Import lädt die letzten Tage neu (*Einstellungen → Import und Crawler*).

**URL Inspection meldet „Quota exceeded“.** Google erlaubt etwa 2.000 Abfragen je Tag und Property; die Stichprobe hört dann für den Tag auf.

**„Jetzt prüfen“ bricht ab.** Prüfungen aus dem Backend sind auf 20 Sekunden begrenzt, der Rest läuft über den Cronjob.

## English summary

vSEO tracks your own websites' visibility in Google from within the REDAXO backend. Its core metric is the **number of ranking queries and URLs**, which reveals URL migrations without redirects within a week, even when clicks barely move.

**Features:** overview of all domains, weekly history with Google updates, winners/losers between two periods (CSV export), lost URLs with their current status code, technical checks (robots.txt, sitemaps, crawler issues with history, URL Inspection sample), optional Matomo data (search visits, entry pages with revenue, 404s), alerts with configurable rules and e-mail, dashboard widget.

**Setup:**
1. Enable the *Google Search Console API* in a Google Cloud project.
2. Create a service account and a JSON key.
3. Add the service account's e-mail as a user to each Search Console property ("Restricted" is enough).
4. Paste the JSON key under *Settings → Accounts* (stored encrypted; the encryption key lives in `redaxo/data/addons/vseo/secret.key` or the `VSEO_KEY` environment variable).
5. Adopt the suggested domains under *Settings → Domains*.
6. Optionally add a Matomo URL and token with view access, then pick the Matomo site per domain.
7. Create the cronjobs *Import*, *Crawler* and *Alerts*.
8. Set recipients and rules under *Settings → Alert rules*.

Console commands, permissions and extension points (`VSEO_URL_SOURCE`, `VSEO_ALERT`, `VSEO_DATA_SOURCES`, `VSEO_RANK_TRACKERS`) are described above.

## Lizenz

MIT, siehe [LICENSE](LICENSE). Enthält [uPlot](https://github.com/leeoniya/uPlot) (MIT).
