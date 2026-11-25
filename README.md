# Dolibarr E-Rechnung (einvoice)

**Version 0.3.0** | Eingehende E-Rechnungen (XRechnung, ZUGFeRD/Factur-X) automatisch aus dem Postfach holen, prüfen und als Lieferantenrechnung übernehmen

[![Dolibarr](https://img.shields.io/badge/Dolibarr-18.0%2B-blue.svg)](https://www.dolibarr.org)
[![License](https://img.shields.io/badge/license-GPL--3.0-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://www.php.net)

> ⚠️ **Frühe Version.** Entwickelt und getestet mit Dolibarr 24 und PHP 8.2/8.4. Lieferantenrechnungen werden immer nur als **Entwurf** angelegt – prüfen und freigeben bleibt eure Aufgabe. Vor der Installation ein Backup machen.

---

## Inhalt
- [So läuft es ab](#so-läuft-es-ab)
- [Funktionen](#funktionen)
- [Unterstützte Rechnungsarten](#unterstützte-rechnungsarten)
- [Installation](#installation)
- [Einstellungen](#einstellungen)
- [Microsoft 365 einrichten](#microsoft-365-einrichten)
- [Automatischer Abruf (Cron)](#automatischer-abruf-cron)
- [Sicherheit](#sicherheit)
- [Häufige Fragen](#häufige-fragen)
- [Tests](#tests)
- [Changelog](#changelog)

---

## So läuft es ab

1. **Abruf:** Das Modul liest das Rechnungspostfach – automatisch alle paar Minuten oder per Knopf *Jetzt abrufen*. E-Rechnungen landen im **E-Rechnung Eingang** (Menü *Abrechnung*). Dateien lassen sich auch von Hand hochladen.
2. **Zuordnung:** Der Lieferant wird über USt-ID, Steuernummer oder Namen gefunden. Ist er neu, legt *Lieferant anlegen* ihn mit den Daten aus der Rechnung an (Adresse, USt-ID, Steuernummer, Kontakt, Bankverbindung).
3. **Prüfen:** Die Detailansicht zeigt die Rechnung lesbar (Positionen, Zu-/Abschläge, USt-Aufschlüsselung, Zahlungsdaten, Anhänge) und darüber die **Prüfhinweise**.
4. **Übernehmen:** *Lieferantenrechnung erstellen* legt einen Entwurf mit allen Positionen an, hängt Original, XML und Anhänge an und vergleicht die Summen mit der E-Rechnung.
5. **Wie gewohnt weiter:** In der Lieferantenrechnung prüfen, freigeben, bezahlen.

## Funktionen

### Abruf aus dem Postfach
- **Microsoft 365 über Microsoft Graph** (empfohlen): App-Registrierung mit Anwendungsrecht, per Exchange-RBAC auf **nur ein Postfach** beschränkt
- **Microsoft 365 über IMAP mit OAuth2** (XOAUTH2, Client-Credentials)
- **Jedes andere Postfach über IMAP** mit Benutzername und Passwort (SSL/TLS oder STARTTLS)
- Eigener IMAP-Client, die `php-imap`-Erweiterung (ab PHP 8.4 nicht mehr enthalten) wird nicht benötigt
- Rechnungen in **weitergeleiteten Mails** (Mail als Anhang) werden gefunden
- **Statuszeile** im Eingang: letzter Abruf mit Ergebnis, automatischer Abruf an/aus, nächster Lauf, Warnung wenn der Zeitplaner nicht läuft
- Nach dem Abruf getrennt für Mails **mit** und **ohne** E-Rechnung wählbar: nichts ändern, als gelesen markieren, in Ordner verschieben (wird bei Bedarf angelegt) oder in den Papierkorb
- Dolibarr merkt sich verarbeitete Mails selbst – mit „nichts ändern“ kann ein anderes Programm parallel dasselbe Postfach abrufen

### Eingang
- Liste mit Status: Neu, Übernommen, Keine E-Rechnung, Ignoriert, Fehler – und Filter
- Hochladen von Hand (mehrere Dateien auf einmal)
- **Keine Dubletten:** gleiche Datei (SHA-256), gleiche Mail (Message-ID) und gleiche Rechnung als zweite Datei (Nummer + Art + Verkäufer) werden erkannt
- Optional: normale PDFs ohne E-Rechnung als „Keine E-Rechnung“ ablegen, z. B. zur Erfassung von Hand
- Originaldateien werden unverändert im Dolibarr-Dokumentenverzeichnis abgelegt (`documents/einvoice/inbox/JJJJ/MM/…`)

### Lesbare Rechnungsansicht
- Positionen mit Artikelnummer, Menge, Einheit, Einzelpreis, USt-Satz und Leistungszeitraum
- Zu- und Abschläge, USt-Aufschlüsselung, Netto, Brutto, bereits gezahlt, offener Betrag
- Fälligkeit, Zahlungsbedingungen, Zahlungsart, Bestell-, Vertrags- und Rechnungsbezug, Bemerkungen
- Die Rechnungsdaten (XML) lassen sich aufklappen und herunterladen

### Prüfhinweise
Über der Rechnung steht, was auffällt – oder „keine Auffälligkeiten“:

| Prüfung | Warum |
|---|---|
| Empfänger (USt-ID oder Name) ist nicht eure Firma | fehlgeleitete oder falsch adressierte Rechnungen |
| Profil MINIMUM oder BASIC WL | steuerlich keine vollwertige E-Rechnung, beim Lieferanten nachfordern |
| Rechnungsnummer, Datum, USt-ID/Steuernummer des Lieferanten fehlen | Pflichtangaben nach § 14 UStG |
| USt-ID im falschen Format (DE + 9 Ziffern) | Tippfehler beim Lieferanten |
| IBAN mit falscher Prüfziffer | Zahlung würde scheitern |
| **IBAN weicht von der beim Lieferanten hinterlegten ab** | häufige Betrugsmasche – vor der Zahlung telefonisch bestätigen lassen |
| Fälligkeit/Zahlungsbedingungen fehlen, Fremdwährung, Datum in der Zukunft | Hinweis |
| Rechenfehler (Positionen, Netto, USt, Brutto) | Summen in der E-Rechnung passen nicht zusammen |

### Lieferantenrechnung erstellen
- Entwurf mit allen Positionen, Zu-/Abschlägen, Rechnungsdatum, Fälligkeit, Zahlungsart und Bezügen in der privaten Notiz
- USt-Rundung wie in der E-Rechnung (Summe je Steuersatz), anschließender **Summenabgleich** mit Warnung bei Abweichungen
- Original, XML und **Anhänge** der E-Rechnung werden an die Lieferantenrechnung gehängt
- **Gutschriften:** Auswahl der korrigierten Rechnung (wird über den Rechnungsbezug vorausgewählt)
- **Anzahlungen:** Vorauszahlungs- und Abschlagsrechnungen werden als *Anzahlungsrechnung* angelegt. Bei einer Schlussrechnung mit „bereits gezahlt“ werden die offenen Anzahlungen des Lieferanten zur Auswahl angeboten, die passenden sind vorausgewählt. Verrechnet wird wie mit *In Rabatt umwandeln* von Hand
- Rechnungen ohne Einzelpositionen (MINIMUM, BASIC WL): eine Sammelposition je Steuersatz
- Schutz vor doppelter Übernahme (gleiche Rechnungsnummer beim Lieferanten)

### Anhänge in der E-Rechnung
Eine XRechnung kann rechnungsbegründende Unterlagen enthalten (Stundennachweise, Aufmaße, Lieferscheine, Belege), ein ZUGFeRD-PDF weitere eingebettete Dateien.
- Anzeige unter *Dokumente*, Öffnen bzw. Herunterladen, Übernahme in die Lieferantenrechnung
- Erlaubt sind nur die Dateiarten des Standards: **PDF, PNG, JPG, CSV, XLSX, ODS**. Der Typ wird am **Inhalt** erkannt, nicht am Dateinamen; alles andere wird angezeigt, aber nicht angeboten
- Dateinamen werden bereinigt, Größe begrenzt, doppelte Dateien (in XML und PDF) nur einmal gezeigt
- Verweise auf externe Dateien (Links) werden nur angezeigt und **nie** automatisch geladen

## Unterstützte Rechnungsarten

| Format | Erkennung |
|---|---|
| XRechnung (CII) | ✅ |
| XRechnung (UBL), Rechnung und Gutschrift | ✅ |
| ZUGFeRD 2.x / Factur-X – EN 16931 (Comfort), EXTENDED, XRechnung-Profil | ✅ |
| ZUGFeRD 2.x / Factur-X – BASIC, BASIC WL, MINIMUM | ✅ (mit Prüfhinweis bei BASIC WL / MINIMUM) |
| ZUGFeRD 1.0 | ❌ wird als „Keine E-Rechnung“ behandelt (rechtlich keine E-Rechnung mehr) |
| PDF ohne eingebettete Daten | ❌ optional als „Keine E-Rechnung“ ablegen |

| Rechnungsart (Typcode) | wird in Dolibarr |
|---|---|
| Rechnung (380), Teilrechnung (326), Teil-/Schlussrechnung Bau (876/877) und weitere | Standardrechnung |
| Gutschrift (381) | Gutschrift zur gewählten Rechnung |
| Rechnungskorrektur (384) | Rechnung mit den Beträgen der Korrektur (auch negativ) |
| Vorauszahlungsrechnung (386), Abschlagsrechnung Bau (875) | Anzahlungsrechnung |
| Gutschriftsverfahren (389) | nicht übernommen – das ist keine Lieferantenrechnung |
| Buchungsinformation (751) | Hinweis: laut Absender keine Rechnung |

Geprüft gegen die [KoSIT-XRechnung-Testsuite](https://github.com/itplr-kosit/xrechnung-testsuite) und das [ZUGFeRD-Korpus](https://github.com/ZUGFeRD/corpus): 285 E-Rechnungen werden korrekt gelesen, die übernommenen Summen stimmen auf den Cent.

Noch nicht automatisch übernommen werden Rechnungen in **Fremdwährung** (Hinweis in der Ansicht).

## Installation
1. ZIP aus den [Releases](https://github.com/Gerrett84/dolibarr-einvoice/releases) über *Einstellungen → Module → Externes Modul hochladen* installieren (oder den Ordner nach `htdocs/custom/einvoice` kopieren)
2. Modul **E-Rechnung** aktivieren
3. Rechte vergeben: „Eingegangene E-Rechnungen ansehen“ und „E-Rechnungen abrufen, hochladen und bearbeiten“. Zum Übernehmen braucht der Benutzer außerdem das Recht, Lieferantenrechnungen anzulegen (für *Lieferant anlegen*: Geschäftspartner anlegen)
4. Einstellungen öffnen: *Start → Einstellungen → Module*, beim Modul **E-Rechnung** auf das Zahnrad klicken (nur für Administratoren). Reiter *Postfach*: Verbindung einrichten und **Verbindung testen** (liest nur, ändert nichts)
5. Reiter *Verarbeitung*: Abrufdatum, Verhalten nach dem Abruf und **Automatischer Abruf** festlegen
6. Für den automatischen Abruf muss der Dolibarr-Zeitplaner auf dem Server laufen, siehe [Automatischer Abruf](#automatischer-abruf-cron)

## Einstellungen
Erreichbar über *Start → Einstellungen → Module → E-Rechnung* (Zahnrad), nicht über das Seitenmenü.

**Reiter „Postfach“**
- Verbindungsart: Microsoft Graph, IMAP mit OAuth2 oder IMAP mit Passwort
- Postfach-Adresse, Verzeichnis-ID, Anwendungs-ID, geheimer Clientschlüssel (bzw. IMAP-Server, Port, Anmeldename, Passwort)
- Ordner im Postfach (leer = Posteingang, Unterordner mit `/`) und Papierkorb-Ordner

**Reiter „Verarbeitung“**
- *Mails abrufen ab*: ältere Mails werden nie angefasst
- *Höchstens Mails pro Abruf*
- Verhalten nach dem Abruf, getrennt für Mails mit und ohne E-Rechnung
- *Auch PDFs ohne E-Rechnung übernehmen*
- *Automatischer Abruf* an/aus und Intervall

## Microsoft 365 einrichten

### Variante A: Microsoft Graph (empfohlen)
1. **Entra ID → App-Registrierungen → Neue Registrierung**, Name z. B. `Dolibarr E-Rechnung`, nur dieses Verzeichnis
2. Verzeichnis-ID (Tenant) und Anwendungs-ID (Client) notieren
3. **Zertifikate & Geheimnisse → Neuer geheimer Clientschlüssel**, den *Wert* notieren (Ablaufdatum merken!)
4. In **Exchange Online PowerShell** den Zugriff auf das Rechnungspostfach beschränken (RBAC für Anwendungen):
   ```powershell
   Connect-ExchangeOnline
   # Objekt-ID der *Unternehmensanwendung* (Enterprise App), nicht der App-Registrierung
   New-ServicePrincipal -AppId <Client-ID> -ObjectId <Objekt-ID> -DisplayName "Dolibarr E-Rechnung"
   New-ManagementScope -Name "Nur Rechnungspostfach" -RecipientRestrictionFilter "PrimarySmtpAddress -eq 'rechnung@example.com'"
   New-ManagementRoleAssignment -App <Client-ID> -Role "Application Mail.ReadWrite" -CustomResourceScope "Nur Rechnungspostfach"
   # Prüfen (sollte True liefern, für andere Postfächer False):
   Test-ServicePrincipalAuthorization -Identity <Client-ID> -Resource rechnung@example.com
   ```
   **Keine** Graph-Berechtigung `Mail.ReadWrite` in Entra erteilen – die würde für alle Postfächer gelten.

### Variante B: IMAP mit OAuth2
1. App registrieren wie oben (Schritte 1–3)
2. **API-Berechtigungen → Berechtigung hinzufügen → APIs in meiner Organisation → Office 365 Exchange Online → Anwendungsberechtigungen → `IMAP.AccessAsApp`**, dann Administratorzustimmung erteilen
3. In Exchange Online PowerShell:
   ```powershell
   New-ServicePrincipal -AppId <Client-ID> -ObjectId <Objekt-ID>
   Add-MailboxPermission -Identity rechnung@example.com -User <Objekt-ID> -AccessRights FullAccess
   ```
4. IMAP muss für das Postfach aktiviert sein

## Automatischer Abruf (Cron)
Der automatische Abruf ist ein geplanter Job von Dolibarr. Er wird in den Einstellungen des Moduls ein- und ausgeschaltet. Damit geplante Jobs überhaupt laufen, muss der Dolibarr-Zeitplaner **einmal auf dem Server** eingerichtet sein, z. B. `/etc/cron.d/dolibarr`:

```cron
*/5 * * * * www-data /usr/bin/php /usr/share/dolibarr/scripts/cron/cron_run_jobs.php <CRON_KEY> <Benutzer> > /var/log/dolibarr-cron.log 2>&1
```

Den Schlüssel (`CRON_KEY`) gibt es unter *Einstellungen → Module → Geplante Jobs → Einstellungen*. Achtung: Läuft der Zeitplaner zum ersten Mal, werden auch **alle anderen** aktiven, überfälligen Jobs ausgeführt (z. B. wiederkehrende Rechnungen) – vorher die Liste der geplanten Jobs prüfen.

Die Statuszeile im Eingang warnt, wenn der nächste Lauf überfällig ist.

## Sicherheit
- Geheimer Clientschlüssel und IMAP-Passwort werden von Dolibarr verschlüsselt gespeichert und nie wieder angezeigt
- XML wird ohne DTD, ohne Netzwerkzugriff und ohne Entitäten geladen (kein XXE)
- Anhänge: Dateiart am Inhalt geprüft, nur zugelassene Typen, Download mit `nosniff` und Sandbox-CSP, keine externen Links geladen
- Schutz vor „ZIP-Bomben“ beim Entpacken eingebetteter PDF-Dateien
- Keine externen Programme, kein `exec`, keine Composer-Abhängigkeiten, kein Java
- Netzwerkzugriff nur zu Microsoft (`login.microsoftonline.com`, `graph.microsoft.com`) bzw. zum eingestellten IMAP-Server
- Alle Aktionen mit CSRF-Token, Rechte werden auf jeder Seite geprüft

## Häufige Fragen

**Ruft ein anderes Programm dasselbe Postfach ab?**
Dann bei beiden Einstellungen nach dem Abruf „nichts ändern“ wählen. Dolibarr merkt sich verarbeitete Mails und holt keine doppelt.

**Woran erkenne ich eine E-Rechnung in einer Mail?**
Eine XRechnung ist eine XML-Datei als Anhang. Bei ZUGFeRD/Factur-X steckt die XML unsichtbar im PDF. Das Modul erkennt beides automatisch; maßgeblich ist rechtlich immer die XML, nicht das sichtbare PDF.

**Eine Rechnung wird falsch gelesen oder abgelehnt?**
Bitte ein [Issue](https://github.com/Gerrett84/dolibarr-einvoice/issues) anlegen – ohne echte Rechnungsdaten, gern mit einem nachgebauten Beispiel.

**Was ist mit ausgehenden Rechnungen?**
Geplant: eigene Rechnungen als ZUGFeRD/XRechnung erzeugen.

## Tests
```bash
php tests/run_tests.php                       # Parser, Details, Prüfungen, Anhänge
php tests/fake_imap_server.php 10143 /tmp/imap.log & sleep 1
php tests/run_imap_test.php 10143 /tmp/imap.log  # IMAP-Client gegen Test-Server
php tests/run_corpus.php <Ordner>             # z. B. Klone der KoSIT-Testsuite und des ZUGFeRD-Korpus
```
Die Tests brauchen kein Dolibarr. Der Ordner `tests/` ist nicht im Release-ZIP enthalten.

## Changelog

### v0.3.0
- **Prüfhinweise** in der Detailansicht: Empfänger, Profil, Pflichtangaben, USt-ID-Format, IBAN-Prüfziffer, geänderte Bankverbindung gegenüber dem Lieferanten, Rechenfehler
- **Anhänge** aus XRechnung und ZUGFeRD-PDF: Anzeige, Download, Übernahme in die Lieferantenrechnung; Dubletten zwischen XML und PDF werden zusammengefasst
- **Anzahlungen:** Vorauszahlungs- und Abschlagsrechnungen (Typ 386, 875) werden als Anzahlungsrechnung angelegt; bei Schlussrechnungen werden offene Anzahlungen zur Verrechnung angeboten und passende vorausgewählt
- PDF-Lesen robuster: falsche `/Length`-Angaben, beschädigtes Stream-Ende, `/Subtype /application#2Foctet-stream`
- Einstellungen nur noch über die Modulverwaltung (Zahnrad), nicht mehr im Seitenmenü; der automatische Abruf bleibt beim Aus- und Einschalten des Moduls erhalten
- Alle Meldungen übersetzt (auch Fehler von IMAP, Microsoft und beim Lesen der XML); die Meldung des automatischen Abrufs erscheint in der Sprache des Betrachters

### v0.2.0
- Lieferantenrechnung (bzw. Gutschrift) als Entwurf aus der E-Rechnung erstellen: Positionen, Zu-/Abschläge, Fälligkeit, Zahlungsart, Original + XML als Anhang, Summenabgleich
- Lesbare Rechnungsansicht mit Positionen, USt-Aufschlüsselung und Rechenprüfung
- Lieferant aus den Rechnungsdaten anlegen (Adresse, USt-ID, Steuernummer, Kontakt, Bankverbindung)
- Profile MINIMUM / BASIC WL ohne Positionen: Sammelposition je Steuersatz
- USt-Rundung wie in der E-Rechnung (Rundung der Summe je Steuersatz); negative Nachlässe in Korrekturrechnungen
- Gutschriftsverfahren (Typ 389) wird nicht als Lieferantenrechnung übernommen
- Geprüft gegen die KoSIT-XRechnung-Testsuite und das ZUGFeRD-Korpus
- Statuszeile im Eingang und Schalter für den automatischen Abruf in den Einstellungen

### v0.1.0
- Erste Version: Abruf über Microsoft Graph, IMAP mit OAuth2 oder Passwort; Erkennung von XRechnung (CII/UBL) und ZUGFeRD/Factur-X; Eingang mit Status, Upload und Detailansicht; wählbares Verhalten nach dem Abruf

## Lizenz
GPL-3.0 oder später, siehe [LICENSE](LICENSE).
