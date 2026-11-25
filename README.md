# Dolibarr E-Rechnung (einvoice)

**Version 0.2.0** | E-Rechnungen (XRechnung, ZUGFeRD/Factur-X) automatisch aus dem Postfach holen

[![Dolibarr](https://img.shields.io/badge/Dolibarr-18.0%2B-blue.svg)](https://www.dolibarr.org)
[![License](https://img.shields.io/badge/license-GPL--3.0-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://www.php.net)

> ⚠️ **Frühe Version.** Diese Version holt E-Rechnungen ab und sammelt sie in einem Eingang. Lieferantenrechnungen werden noch **nicht** automatisch angelegt (geplant für 0.2). Installation und Nutzung auf eigene Verantwortung, vorher Backup machen.

---

## Funktionen

### Abruf aus dem Postfach
- **Microsoft 365 über Microsoft Graph** (empfohlen): App-Registrierung mit Anwendungsrecht, per Exchange-RBAC auf **nur ein Postfach** beschränkt
- **Microsoft 365 über IMAP mit OAuth2** (XOAUTH2, Client-Credentials)
- **Jedes andere Postfach über IMAP** mit Benutzername und Passwort (SSL/TLS oder STARTTLS)
- Eigener IMAP-Client ohne `php-imap`-Erweiterung (die es ab PHP 8.4 nicht mehr gibt)
- Abruf per Knopf oder als geplanter Job (Standard: alle 15 Minuten, muss aktiviert werden)

### Was danach mit der Mail passiert – frei wählbar
Getrennt für Mails **mit** und **ohne** E-Rechnung:
- nichts ändern (Dolibarr merkt sich verarbeitete Mails selbst – geeignet, wenn noch ein anderes Programm dasselbe Postfach abruft)
- als gelesen markieren
- in einen Ordner verschieben (wird bei Bedarf angelegt, Unterordner mit `/`)
- in den Papierkorb

### Erkennung
- **XRechnung** in CII- und UBL-Syntax (Rechnung und Gutschrift)
- **ZUGFeRD / Factur-X**: PDF/A-3 mit eingebetteter XML
- Rechnungen in **weitergeleiteten Mails** (Mail als Anhang) werden gefunden
- Optional: normale PDFs ohne E-Rechnung als „Keine E-Rechnung“ ablegen
- Gelesen werden Rechnungsnummer, Datum, Art (Rechnung/Gutschrift/…), Verkäufer mit USt-ID und Steuernummer, Käuferreferenz/Leitweg-ID, Beträge, Währung
- Lieferant wird über **USt-ID**, dann Steuernummer, dann exakten Namen zugeordnet (nur bei eindeutigem Treffer)

### Eingang
- Liste mit Status: Offen, Übernommen, Keine E-Rechnung, Ignoriert, Fehler
- Detailansicht mit Original-Datei, eingebetteter XML und XML-Ansicht
- Hochladen von Hand (mehrere Dateien)
- Keine Dubletten: gleiche Datei (SHA-256) und gleiche Mail (Message-ID) werden nur einmal übernommen
- Originaldateien werden unverändert im Dolibarr-Dokumentenverzeichnis abgelegt (`documents/einvoice/inbox/JJJJ/MM/…`)

## Sicherheit
- Geheimer Clientschlüssel und IMAP-Passwort werden von Dolibarr verschlüsselt gespeichert und nie wieder angezeigt
- XML wird ohne DTD, ohne Netzwerkzugriff und ohne Entitäten geladen (kein XXE)
- Keine externen Programme, kein `exec`, keine Composer-Abhängigkeiten
- Zugriff nur über Microsoft (`login.microsoftonline.com`, `graph.microsoft.com`) bzw. den eingestellten IMAP-Server

## Installation
1. ZIP aus den [Releases](https://github.com/Gerrett84/dolibarr-einvoice/releases) über *Einstellungen → Module → Externes Modul hochladen* installieren (oder Ordner nach `htdocs/custom/einvoice` kopieren)
2. Modul **E-Rechnung** aktivieren
3. Rechte vergeben: „Eingegangene E-Rechnungen ansehen“ und „E-Rechnungen abrufen, hochladen und bearbeiten“
4. *E-Rechnung → Einstellungen*: Postfach einrichten, „Verbindung testen“, Abrufdatum und Verhalten festlegen
5. Optional: geplanten Job „E-Rechnungen aus dem Postfach abrufen“ aktivieren

## Microsoft 365 einrichten

### Variante A: Microsoft Graph (empfohlen)
1. **Entra ID → App-Registrierungen → Neue Registrierung**, Name z. B. `Dolibarr E-Rechnung`, nur dieses Verzeichnis
2. Verzeichnis-ID (Tenant) und Anwendungs-ID (Client) notieren
3. **Zertifikate & Geheimnisse → Neuer geheimer Clientschlüssel**, den *Wert* notieren
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

## Geplant
- 0.2: Lieferantenrechnung (Entwurf) aus der E-Rechnung anlegen, lesbare Rechnungsansicht, Plausibilitätsprüfung der Summen
- später: Ausgang – eigene Rechnungen als ZUGFeRD/XRechnung erzeugen

## Tests
```bash
php tests/run_tests.php
php tests/fake_imap_server.php 10143 /tmp/imap.log & sleep 1; php tests/run_imap_test.php 10143 /tmp/imap.log
```

## Changelog

### v0.2.0
- Lieferantenrechnung (bzw. Gutschrift) als Entwurf aus der E-Rechnung erstellen: Positionen, Zu-/Abschläge, Fälligkeit, Zahlungsart, Original + XML als Anhang, Summenabgleich
- Lesbare Rechnungsansicht mit Positionen, USt-Aufschlüsselung und Rechenprüfung
- Lieferant aus den Rechnungsdaten anlegen (Adresse, USt-ID, Steuernummer, Kontakt, Bankverbindung)
- Statuszeile im Eingang (letzter Abruf, Ergebnis, automatischer Abruf) und Schalter für den automatischen Abruf in den Einstellungen

### v0.1.0
- Erste Version: Abruf über Microsoft Graph, IMAP mit OAuth2 oder Passwort; Erkennung von XRechnung (CII/UBL) und ZUGFeRD/Factur-X; Eingang mit Status, Upload und Detailansicht; wählbares Verhalten nach dem Abruf

## Lizenz
GPL-3.0 oder später, siehe [LICENSE](LICENSE).
