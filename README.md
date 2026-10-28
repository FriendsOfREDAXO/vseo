# SEO Monitor für REDAXO (vsn_seo)

> Vollständige Anleitung folgt mit Meilenstein 4.

Überwacht die Sichtbarkeit eigener Websites in Google: Klicks, Impressionen und vor allem die **Anzahl rankender Suchbegriffe und URLs** – damit Einbrüche nach einem URL-Umbau innerhalb einer Woche auffallen.

## Kurz: Einrichtung

1. Google-Cloud-Projekt anlegen, **Google Search Console API** aktivieren.
2. Dienstkonto erstellen, JSON-Schlüssel herunterladen.
3. Die E-Mail-Adresse des Dienstkontos in der Search Console je Property als Nutzer hinzufügen.
4. Im Backend unter *SEO Monitor → Einstellungen → Zugänge* den JSON-Schlüssel hinterlegen.
5. Unter *Domains* die Vorschläge übernehmen oder Domains anlegen.
6. Cronjob „SEO Monitor: Import“ anlegen (während des Backfills stündlich) oder `php redaxo/bin/console vsn_seo:import`.

## License

MIT
