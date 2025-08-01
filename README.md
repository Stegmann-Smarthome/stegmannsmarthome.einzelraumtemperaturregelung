# Einzelraumregelung: Heizung
### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Konfigurationsseite](#4-konfigurationsseite)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [Bedienung](#6-bedienung)
7. [Versionshistorie](#6-versionshistorie)

### 1. Funktionsumfang

Mit diesem Modul lässt sich die Temperatur in einem Raum steuern.

### 2. Voraussetzungen

- IP-Symcon ab Version .8.0
  (Getestet & Entwickelt)

### 3. Software-Installation

* Das Modul läst sich per Modul-Control, über folgende URL hinzufügen:  
  https://github.com/Stegmann-Smarthome/stegmannsmarthome.einzelraumtemperaturregelung.git
  
### 4. Instanzkonfiguration

Auf der Instanzkonfigurationsseite des Moduls / der Module, gibt es folgende Konfigurationsmöglichkeiten.

#### Instanzkonfigurationsseite: Einzelraumtemperaturregelung Einstellungen

Name                 | Typ         | Beschreibung
-------------------- | ----------- | ------------------------------------------------------
Frostschutztemperatur| Boolean     | Temperatur bei deaktiviertem Heizmodus + Urlaubsmodus

#### Instanzkonfigurationsseite: Einzelraumtemperaturregelung Aktor

Name                            | Beschreibung
--------------------            | --------------------------------------------------------------------
Ist-Temperatur                  | Auswahl der ID, der Variable mit dem aktuellen Temperaturwert
Soll-Temperatur                 | Auswahl der ID, der die aktuelle Raumtemperatur misst
Einstellungen-Modul             | Auswahl der erstellten Einzelraumtemperaturregelung: Einstellungen
Wochenplanerstellung            | Erstellung zur Auswahl eines Wochenplans
Variable: Aktueller Heizstatus  | Zum Ein- und Ausblenden der Variablen "Heizphase" im Webfrontend
Auswahl Tür- & Fenstersensoren  | Zum auswählen der Tür- / Fenstersensoren die ausgewertet werden sollen
Tür- und Fensterkontakt: Absenktemperatur | Angabe der Temperatur auf die Abgesenkt werden soll
Tür- und Fensterkontakt: Meldeverzögerung | Angabe nach welcher Zeit die Temperatur auf die Absenktemperatur gesetzt werden soll


### 5. Statusvariablen und Profile

Die Module legen beim Anlegen automatisch die folgenden Variablen und Profile an.
Das Löschen von einzelnen Elementen kann zu Fehlfunktionen führen.

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen: Einzelraumtemperaturregelung Einstellungen

Name                 | Typ         | Beschreibung
-------------------- | ----------- | ------------------------------------------------------
Heizung              | Boolean     | Heizungs Ein/Aus
Urlaub               | Boolean     | Urlaub Ein/Aus

#### Statusvariablen: Einzelraumtemperaturregelung Aktor

Name                 | Typ         | Beschreibung
-------------------- | ----------- | ------------------------------------------------------
Ist-Temperatur       | Link        | Aktuelle Temperatur des Temperatursensors
Soll-Temperatur      | Float       | Gewünschte Temperatur die gesetzt werden soll
Absenken             | Float       | Wert um den die Soll-Temperatur abgesenkt werden soll wenn die Heizphase "Absenken" aktiv ist
Heizphase            | Integer     | Anzeige einer Variablen, welche die aktuelle Heizphase anzeigt
Kontakt              | Boolean     | Anzeige ob einer der zugeordneten Kontakte aktuell geöffnet ist

#### Profile: Einzelraumtemperaturregelung Einstellungen

Name                        | Typ
--------------------------- | -------
-----                       | -----

#### Profile: Einzelraumtemperaturregelung Aktor

Name                        | Typ
--------------------------- | -------
SS.ETR.Heizphase            | Integer

### 6. Bedienung

Im WebFront / APP stehen folgende Bedienelemente zur Verfügung.

#### Einzelraumtemperaturregelung Einstellungen
Schalter zum aktivieren und deaktivieren der Heizungungssteuerung
Schalter zum aktivieren und deaktivieren des Urlaubsmodus

#### Einzelraumtemperaturregelung: Aktor
Slider zum verändern der Soll-Temperatur-Einstellung und die Anzeige der verlinkten Ist-Temperatur
(Darstellung als Kachel: Thermostat)

### 7. Versionshistorie
24.04.2025 - Version 1.0
29.04.2025 - Wochenplan-Feature (zusätzliche Absenkung)