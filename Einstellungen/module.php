<?php

// Definiert eine neue Klasse namens "RaumregelungEinstellungen", die von IPSModule erbt
class Einstellungen extends IPSModule
{
    // Diese Funktion wird einmal aufgerufen, wenn eine Istanz des Moduls erstellt wird
    // In diesem Bereich werden alle Variablen, Aktionen und Eigenschaften angelegt, die das Modul nutzt
    public function Create()
    {
        // Diese Zeile muss immer vorhanden sein, damit das Modul richtig funktioniert
        // Eltern-Konstruktor aufrufen, damit das Modul korrekt initialisiert wird
        parent::Create();

        ############################## Erstellen von Variablen im Objektbaum + Zuweisung einer Darstellung
        // Boolean-Variable für Ein/Aus-Schalter Heizung
        $this->RegisterVariableBoolean("heating_status", $this->Translate("Heating status"), "", 0);
        // Boolean-Variable für Urlaubsmodus
        $this->RegisterVariableBoolean("vacation_status", $this->Translate("Vacation mode"), "", 1);

        ############################## Aktivieren der Variablenaktion (Standardaktion)
        // Erlaubt Aktionen, wenn der Benutzer im WebFront den Schalter umlegt
        $this->EnableAction("heating_status");
        $this->EnableAction("vacation_status");

        ############################## Erstellen von Eigenschaften für das Konfigurationsformular (form.json)
        // Frostschutz-Temperatur (Standard: 5)
        $this->RegisterPropertyInteger("frost_protection_form", 5);
        // JSON-formatierte Liste von Actor-Instanz-IDs
        $this->RegisterPropertyString('actor_list', '[]');

        ##############################  Initialisieren des Attributes für die Backup-Werte der Aktoren
        $this->RegisterAttributeString("BackupActorSollTemp", ""); 

    }


    // Diese Funktion wird aufgerufen, wenn das Modul gelöscht wird
    // Hier könnten Aufräumarbeiten stehen, was ausgeführt werden soll, die Instanz des Moduls gelöscht wird
    public function Destroy()
    {
        // Diese Zeile muss immer vorhanden sein
        parent::Destroy();
    }


    // Diese Funktion wird aufgerufen, wenn sich etwas an der Instanz ändert
    public function ApplyChanges()
    {
        // Diese Zeile muss immer vorhanden sein
        parent::ApplyChanges();

        // Auslesen der ausgewählten Aktoren-IDs aus der Modulinstanz via JSON
        $jsonString = $this->ReadPropertyString('actor_list');
        // Wandle den JSON-String in ein Array um
        $actorList = json_decode($jsonString, true);

        // Prüfe, ob die Umwandlung erfolgreich war und verarbeite die Liste
        if (is_array($actorList)) {
            // Für jeden Eintrag in der Liste die Instanz-ID extrahieren
            foreach ($actorList as $actor) {
                // Falls der Actor ein Array ist, extrahiere die InstanceID
                if (is_array($actor) && isset($actor['InstanceID'])) {
                    $actorID = $actor['InstanceID'];
                } else {
                    // Falls $actor schon direkt die ID ist
                    $actorID = $actor;
                }
                IPS_LogMessage('RaumregelungEinstellungen', 'Verarbeiteter Actor: ' . $actorID);
            }
        } else {
            IPS_LogMessage('RaumregelungEinstellungen', 'Die Actor-Liste konnte nicht als Array interpretiert werden.');
        }
    }

    ##############################  Abfangen von Benutzeraktionen im WebFront
    public function RequestAction($Ident, $Value)
    {  
        // Auslesen der ausgewählten Aktoren-IDs aus der Modulinstanz via JSON
        $jsonString = $this->ReadPropertyString('actor_list');
        // Wandle den JSON-String in ein Array um
        $actorList  = json_decode($jsonString, true);

        // Frostschutzwert auslesen aus Modulinstanzkonfiguration
        $frostschutz = $this->ReadPropertyInteger('frost_protection_form');

        switch ($Ident) {
            case 'heating_status':
                // Heizung ein-/ausschalten
                $this->SetValue("heating_status", $Value);         
                if ($Value === true) {
                    // Heizung einschalten – zuvor gespeicherte Werte wiederherstellen, wenn vorhanden
                    $attribute = $this->ReadAttributeString("BackupActorSollTemp");
                    if (!empty($attribute)) {
                        $backupValues = json_decode($attribute, true);
                        if (is_array($backupValues)) {
                            foreach ($actorList as $actor) {
                                if (is_array($actor) && isset($actor['InstanceID'])) {
                                    $actorID = $actor['InstanceID'];
                                } else {
                                    $actorID = $actor;
                                }
                                // Falls ein gesicherter Wert vorliegt, wird er wieder gesetzt
                                if (isset($backupValues[$actorID])) {
                                    RequestAction($actorID, $backupValues[$actorID]);
                                    IPS_LogMessage('RaumregelungEinstellungen', 'Actor-ID ' . $actorID . ' wurde auf den gesicherten Wert ' . $backupValues[$actorID] . ' zurückgesetzt');
                                } else {
                                    IPS_LogMessage('RaumregelungEinstellungen', 'Kein gesicherter Wert für Actor-ID ' . $actorID . ' gefunden');
                                }
                                // Bedienung im WebFront aktivieren
                                IPS_SetDisabled($actorID, false);
                            }


                            
                            
                            
                        } else {
                            IPS_LogMessage('RaumregelungEinstellungen', 'Die gesicherten Actor-Werte konnten nicht dekodiert werden.');
                        }
                    } else {
                        IPS_LogMessage('RaumregelungEinstellungen', 'Kein Backup der Actor-Werte vorhanden.');
                    }

                    // Urlaubsmodus wieder aktivierbar machen
                    $urlaubsmodusid = $this->GetIDForIdent("vacation_status");
                    IPS_SetDisabled($urlaubsmodusid, false);

                } else {
                    //Heizung ausschalten – Backup erstellen und Frostschutz aktivieren
                    $backupValues = array();
                    foreach ($actorList as $actor) {
                        if (is_array($actor) && isset($actor['InstanceID'])) {
                            $actorID = $actor['InstanceID'];
                            // Deaktivierung der Bedienung im Webfront, wenn Heizung aus
                            IPS_SetDisabled($actorID, true);
                        } else {
                            // Wenn Heizung ausgeschaltet wird:
                            // Gespeicherte Werte aus dem Backup-Attribut holen
                            $actorID = $actor;
                        }
                        
                        // Sicherung der aktuellen Temperatueinstellung aller Variablen / Backup als JSON-String im Attribut speichern
                        $currentValue = GetValue($actorID);
                        $backupValues[$actorID] = $currentValue;
                        // Setze den neuen Wert, in dem Fall den Wert des Frostschutzes
                        RequestAction($actorID, $frostschutz);
                        IPS_LogMessage('RaumregelungEinstellungen', 'Actor-ID ' . $actorID . ' gesichert mit Wert ' . $currentValue . ' und auf 8 gesetzt');
                    }
                    // Speichern des Backups als JSON im Attribut "BackupActorSollTemp"
                    $this->WriteAttributeString("BackupActorSollTemp", json_encode($backupValues));

                    // Deaktivieren des Urlaubsmodus, wenn der Heizmodus deaktiviert wird
                    // Frostschutzwert auslesen aus Modulinstanzkonfiguration
                    #$urlaubsstatus = $this->GetValue("vacation_status");
                    $this->SetValue("vacation_status", 0);


                    $urlaubsmodusid = $this->GetIDForIdent("vacation_status");
                    IPS_SetDisabled($urlaubsmodusid, true);
                    }
                break;

                

            case 'vacation_status':
                // Setzt den neuen Wert für "vacation_status"
                $this->SetValue("vacation_status", $Value);
                //Urlaubsmodus aktivieren – alle Actors auf Frostschutz setzen
                if ($Value === true) {
                    foreach ($actorList as $actor) {
                        if (is_array($actor) && isset($actor['InstanceID'])) {
                            $actorID = $actor['InstanceID'];
                            RequestAction($actorID, $frostschutz);
                        } 

                    // Setze den neuen Wert, in dem Fall den Wert des Frostschutzes
                    IPS_LogMessage('RaumregelungEinstellungen', 'Actor-ID' . $actorID . ' gesichert mit Wert ' . $frostschutz);

                    // Aktivieren der Bedienung im Webfront, wenn Heizung aus
                    IPS_SetDisabled($actorID, true);
                    }




                } else {
                    // Beim Ausschalten des Heizmodus sollen die gesicherten Werte wiederhergestellt werden
                    // Wenn Heizung ausgeschaltet wird: Gespeicherte Werte aus dem Backup-Attribut holen
                    $attribute = $this->ReadAttributeString("BackupActorSollTemp");
                    if (!empty($attribute)) {
                        $backupValues = json_decode($attribute, true);
                        if (is_array($backupValues)) {
                            foreach ($actorList as $actor) {
                                if (is_array($actor) && isset($actor['InstanceID'])) {
                                    $actorID = $actor['InstanceID'];
                                } 
                                // Falls ein gesicherter Wert vorliegt, wird er wieder gesetzt
                                if (isset($backupValues[$actorID])) {
                                    RequestAction($actorID, $backupValues[$actorID]);
                                    IPS_LogMessage('RaumregelungEinstellungen', 'Actor-ID ' . $actorID . ' wurde auf den gesicherten Wert ' . $backupValues[$actorID] . ' zurückgesetzt');
                                } else {
                                    IPS_LogMessage('RaumregelungEinstellungen', 'Kein gesicherter Wert für Actor-ID ' . $actorID . ' gefunden');
                                }
                                // Aktivieren der Bedienung im Webfront, wenn Heizung aus
                                IPS_SetDisabled($actorID, false);

                            }
                        } else {
                            IPS_LogMessage('RaumregelungEinstellungen', 'Die gesicherten Actor-Werte konnten nicht dekodiert werden.');
                        }
                    } else {
                        IPS_LogMessage('RaumregelungEinstellungen', 'Kein Backup der Actor-Werte vorhanden.');
                    }
                }
                break;
        }
    }
}
?>