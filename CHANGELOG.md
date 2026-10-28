# Changelog

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
