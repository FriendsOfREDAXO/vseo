# Changelog

## 1.0.0-beta1 – unveröffentlicht

- Vollständige Anleitung (Deutsch, englische Kurzfassung)
- Rank-Tracking: Schnittstelle `RankTrackerInterface`, Ergebnisobjekt `RankResult`, Tabellen und Extension Point `VSN_SEO_RANK_TRACKERS` (ohne eigene Implementierung)
- GitHub-Action: `php -l` für PHP 8.1–8.4 und PHPStan (Level 6) gegen den REDAXO-Core
- Diagrammlegende übersetzt

## 0.3.0 – unveröffentlicht

- Warnungen mit einstellbaren Regeln (Suchbegriffe, URLs, Klicks, 404 aus Suchmaschinen, neue Fehlerseiten, robots.txt/Sitemap, indexierter Anteil, Weiterleitungsketten, veraltete Daten), Verlauf mit „gesehen“, Ampel in der Übersicht
- Mails über rex_mailer: sofort je Regel, wöchentliche Zusammenfassung je Empfänger, Testmail
- Extension Point `VSN_SEO_ALERT` für Warnungen anderer AddOns
- Matomo-Import: Besuche aus Suchmaschinen, Suchmaschinen, Einstiegsseiten aus der Suche mit Umsatz (Matomo 5), 404-Seiten nach einstellbarem Muster; Domain-Seite „Matomo“
- URL-Inspection-Stichprobe (verlorene, neue und Top-URLs): Indexstatus, Google-canonical, letzter Crawl
- robots.txt und Sitemaps folgen Weiterleitungen
- Widget für das AddOn „dashboard“
- Cronjob-Typ und Konsolenbefehl `vsn_seo:alerts`

## 0.2.0 – unveröffentlicht

- Gewinner/Verlierer: zwei frei wählbare Zeiträume je Suchbegriff oder URL, Filter nach Gerät, Land und Text, CSV-Export
- Verlorene URLs mit aktuellem Statuscode aus dem Crawler, Sofortprüfung aus dem Backend
- Crawler: robots.txt, Sitemaps (auch Index und gzip), URLs aus Sitemap, Search Console und `VSN_SEO_URL_SOURCE`; Statuscode, Weiterleitungsketten, canonical, noindex/X-Robots-Tag, robots.txt-Sperren, Title/Description inkl. Duplikaten, hreflang
- Problemliste je Domain mit Verlauf (seit, zuletzt gesehen, behoben)
- Sitemap-Status aus der Search Console (eingereicht, Fehler, Warnungen, zuletzt gelesen)
- Cronjob-Typ und Konsolenbefehl `vsn_seo:crawl`

## 0.1.0 – unveröffentlicht

- Gerüst, Rechte, Einstellungen
- Zugänge für Search Console (Dienstkonto, verschlüsselt) und Matomo, unabhängig von anderen AddOns
- Domains mit Vorschlägen aus YRewrite und Search Console, auch externe Domains
- Import Search Analytics (date × query × page × device × country) mit Backfill in Etappen, Tages- und Wochensummen
- Übersicht über alle Domains, Domain-Verlauf mit Google-Updates
- Cronjob-Typ und Konsolenbefehle `vsn_seo:import`, `vsn_seo:backfill`, `vsn_seo:aggregate`
