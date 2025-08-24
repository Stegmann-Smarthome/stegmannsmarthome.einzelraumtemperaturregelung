<?php

class Aktor extends IPSModule
{
    public function Create()
    {
        parent::Create();

        ############################# Erstellen von neuen Variablenprofilen ##############################
        // Erstellung eines Variablenprofils für die Heizphase inklusive Zuweisung eines Icons
        if (!IPS_VariableProfileExists("SS.ETR.Heizphase")) {
            IPS_CreateVariableProfile("SS.ETR.Heizphase", 1); // 1 = Integer
            IPS_SetVariableProfileAssociation("SS.ETR.Heizphase", 0, "Heizen", "", 0xFF0000);
            IPS_SetVariableProfileAssociation("SS.ETR.Heizphase", 1, "Absenken", "", 0xFF7F00);
            IPS_SetVariableProfileAssociation("SS.ETR.Heizphase", 2, "Frostschutz", "", 0x0000FF);
            IPS_SetVariableProfileAssociation("SS.ETR.Heizphase", 3, "-", "", 0xFFFFFF);
            IPS_SetVariableProfileIcon("SS.ETR.Heizphase", "calendar-range");
        }

        if (!IPS_VariableProfileExists("SS.ETR.Kontakt")) {
            IPS_CreateVariableProfile("SS.ETR.Kontakt", 0); // 0 = Boolean
            IPS_SetVariableProfileAssociation("SS.ETR.Kontakt", false, "Geschlossen", "", 0x00FF00);
            IPS_SetVariableProfileAssociation("SS.ETR.Kontakt", true, "Offen", "", 0x0000FF);
            IPS_SetVariableProfileIcon("SS.ETR.Kontakt", "window-maximize");
        }

        ##############################
        // 1. Soll-Temperatur-Variablen (Slider)
        $this->RegisterVariableFloat(
            "set_heating_temperature",
            $this->Translate("Heating temperature"),
            ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'TEMPLATE' => VARIABLE_TEMPLATE_SLIDER_ROOM_TEMPERATURE, 'USAGE_TYPE' => 0, 'MIN' => 15, 'MAX' => 25],
            1
        );
        $this->EnableAction("set_heating_temperature");

        $this->RegisterVariableFloat(
            "set_lowering_temperature",
            $this->Translate("Lowering temperature"),
            ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'TEMPLATE' => VARIABLE_TEMPLATE_SLIDER_ROOM_TEMPERATURE, 'USAGE_TYPE' => 0, 'MIN' => 0, 'MAX' => 5],
            2
        );
        $this->EnableAction("set_lowering_temperature");

        ##############################
        // 2. Eigenschaften für das Konfigurationsformular
        $this->RegisterPropertyInteger("ID_Aktor", 0);                   // a) ID des physischen Aktors (Variable)
        $this->RegisterPropertyInteger("Is_Temperature", 0);             // b) ID der Ist-Temperatur-Variable
        $this->RegisterPropertyInteger("Weekly_Schedule_Selection", 0);  // c) Auswahl des Wochenplans
        $this->RegisterPropertyInteger("SettingsModuleID", 0);           // d) ID von Modul 1 (Einstellungen)
        $this->RegisterPropertyFloat("FrostProtection", 5);              // e) Frostschutz-Temperatur
        $this->RegisterPropertyBoolean("Actual_Heating_Phase", false);   // f) Anzeige Heizphase?
        $this->RegisterPropertyString('window_sensor', json_encode([])); // g) Fenster-/Türsensoren
        $this->RegisterPropertyFloat('windowdoor_temperature', 10.0);    // h) Absenktemp bei Fenster offen
        $this->RegisterPropertyFloat('windowdoor_reporting_delay', 30.0);// i) Meldeverzögerung
        $this->RegisterPropertyBoolean("Windowdoor_Status", false);      // j) Anzeige Kontaktstatus?

        // Echo-Schutz: Merker für letzte Aktor-Schreibquelle/Zeit
        $this->RegisterAttributeString("LastActorWriteSource", "");
        $this->RegisterAttributeInteger("LastActorWriteTime", 0);

        ##############################
        // 3. Attribute zum Speichern alter Werte
        $this->RegisterAttributeFloat("BackupActorSollTemp", 0.0); // a) Backup-Soll (Heizung/Urlaub)
        $this->RegisterAttributeInteger("WeeklySelection_Old", 0); // b) Alte Auswahl des Wochenplans
        $this->RegisterAttributeInteger("HeatingPlanID", 0);       // c) ID des Wochenplans
        $this->RegisterAttributeInteger("HeatingStatusVarID", 0);  // d) IDs der Boolean-Variablen aus Modul 1
        $this->RegisterAttributeInteger("VacationStatusVarID", 0);

        // Timer Registrierung: ident bleibt "WindowOpenTimer"
        $this->RegisterTimer("WindowOpenTimer", 0, 'IPS_RequestAction(' . $this->InstanceID . ', "WindowOpenTimer", "");');

        // Marker für zuletzt manuell ausgeführte Wochenplan-Aktion
        $this->RegisterAttributeInteger("LastPlanAction", -1); // -1 = kein manueller Marker, 0/1 = Heizen/Absenken
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        ##############################
        // 1. Wochenplan anlegen/aktualisieren, falls sich die Auswahl geändert hat
        $currentPlan = $this->ReadPropertyInteger("Weekly_Schedule_Selection");
        $oldPlan     = $this->ReadAttributeInteger("WeeklySelection_Old");
        if ($currentPlan !== $oldPlan) {
            $this->rebuildWeeklyScheduleForSelection($currentPlan);
            $this->WriteAttributeInteger("WeeklySelection_Old", $currentPlan);
        }

        $planID = $this->ReadAttributeInteger("HeatingPlanID");
        if ($planID > 0 && IPS_EventExists($planID)) {
            // Registriere relevante Nachrichten
            $this->RegisterMessage($planID, EM_UPDATE);
            $this->RegisterMessage($planID, EM_CHANGESCHEDULEGROUP);
            $this->RegisterMessage($planID, EM_CHANGESCHEDULEGROUPPOINT);
            $this->RegisterMessage($planID, EM_CHANGESCHEDULEACTION);
        }

        $this->updateLoweringVisibility();

        ##############################
        // 2. MessageSink-Registrierung für Änderungen in Modul 1
        $settingsID     = $this->ReadPropertyInteger("SettingsModuleID");
        $heatingVarID   = 0;
        $vacationVarID  = 0;
        if ($settingsID > 0 && IPS_InstanceExists($settingsID)) {
            $heatingVarID  = @IPS_GetObjectIDByIdent("heating_status",  $settingsID);
            $vacationVarID = @IPS_GetObjectIDByIdent("vacation_status", $settingsID);
        }
        $this->WriteAttributeInteger("HeatingStatusVarID",  $heatingVarID);
        $this->WriteAttributeInteger("VacationStatusVarID", $vacationVarID);

        if ($heatingVarID > 0) {
            $this->RegisterMessage($heatingVarID, VM_UPDATE);
        }
        if ($vacationVarID > 0) {
            $this->RegisterMessage($vacationVarID, VM_UPDATE);
        }

        // --- Slider initial aktiv/deaktiv je nach Heizung/Urlaub ---
        $heatingActiveInit  = ($heatingVarID  > 0) ? GetValue($heatingVarID)  : true;
        $vacationActiveInit = ($vacationVarID > 0) ? GetValue($vacationVarID) : false;

        $disable = (!$heatingActiveInit || $vacationActiveInit);

        if (($id = $this->GetIDForIdent("set_heating_temperature")) !== false && IPS_VariableExists($id)) {
            IPS_SetDisabled($id, $disable);
        }
        if (($id = $this->GetIDForIdent("set_lowering_temperature")) !== false && IPS_VariableExists($id)) {
            IPS_SetDisabled($id, $disable);
        }


        // === Aktor analog zu den Slidern initial sperren/freigeben ===
        $actorID = $this->ReadPropertyInteger("ID_Aktor");
        if ($actorID > 0 && IPS_VariableExists($actorID)) {
            $disableInit = (!$heatingActiveInit || $vacationActiveInit);
            IPS_SetDisabled($actorID, $disableInit);
        }


        ##############################
        // 3. Link für Ist-Temperatur anlegen/aktualisieren
        $linkID   = @$this->GetIDForIdent("Link_Ist_Temperatur");
        $targetID = $this->ReadPropertyInteger("Is_Temperature");
        if ($linkID !== false && (!IPS_VariableExists($targetID) || $targetID === 0)) {
            IPS_DeleteLink($linkID);
            $linkID = false;
        }
        if ($linkID === false && IPS_VariableExists($targetID) && $targetID > 0) {
            $istLink = IPS_CreateLink();
            IPS_SetName($istLink, "Ist-Temperatur");
            IPS_SetParent($istLink, $this->InstanceID);
            IPS_SetLinkTargetID($istLink, $targetID);
            IPS_SetIdent($istLink, "Link_Ist_Temperatur");
            IPS_SetPosition($istLink, 0);
            $linkID = $istLink;
        }
        if ($linkID !== false && IPS_VariableExists($targetID) && $targetID > 0) {
            IPS_SetLinkTargetID($linkID, $targetID);
            IPS_SetVariableCustomProfile($targetID, "~Temperature");
        }

        ##############################
        // 4.1 Anzeige der aktuellen Heizphase, falls aktiviert
        if ($this->ReadPropertyBoolean("Actual_Heating_Phase")) {
            $varID = @$this->GetIDForIdent("actual_heating_phase");
            if ($varID === false) {
                $id = $this->RegisterVariableInteger(
                    "actual_heating_phase",
                    $this->Translate("Heating phase"),
                    "SS.ETR.Heizphase",
                    5
                );
                IPS_SetIcon($id, "calendar-range");
                IPS_LogMessage("Raumregelung", "Variable actual_heating_phase angelegt.");
            }

            // sofort korrekten Status setzen (auch bei späterem Einschalten)
            $planID = $this->ReadAttributeInteger("HeatingPlanID");
            $this->updateHeatingPhaseState($planID);
        } else {
            $id = @$this->GetIDForIdent("actual_heating_phase");
            if ($id !== false) {
                IPS_DeleteVariable($id);
            }
        }

        // 4.2 Anzeige des Fenster-/Tür-Status, falls aktiviert
        if ($this->ReadPropertyBoolean("Windowdoor_Status")) {
            if (@$this->GetIDForIdent("windowdoor_status") === false) {
                $id = $this->RegisterVariableBoolean(
                    "windowdoor_status",
                    $this->Translate("opening contact"),
                    "SS.ETR.Kontakt",
                    10
                );
                IPS_SetIcon($id, "window-closed");
                IPS_LogMessage("Raumregelung", "Variable windowdoor_status angelegt.");
            }
        } else {
            $id = @$this->GetIDForIdent("windowdoor_status");
            if ($id !== false) {
                IPS_DeleteVariable($id);
            }
        }

        // Aktor-Variable (Solltemperatur) auf VM_UPDATE überwachen
        $actorID = $this->ReadPropertyInteger("ID_Aktor");
        if ($actorID > 0 && IPS_VariableExists($actorID)) {
            $this->RegisterMessage($actorID, VM_UPDATE);
        }

        # 5. Auslesen und registrieren von Änderungen an der Tür- und Fensterauswahl
        $json     = $this->ReadPropertyString('window_sensor');
        $entries  = json_decode($json, true);
        foreach ($entries as $row) {
            $instanceID = $row['InstanceID'];
            if (IPS_VariableExists($instanceID)) {
                $this->RegisterMessage($instanceID, VM_UPDATE);
            }
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $heatingVarID  = $this->ReadAttributeInteger("HeatingStatusVarID");
        $vacationVarID = $this->ReadAttributeInteger("VacationStatusVarID");
        $planID        = $this->ReadAttributeInteger("HeatingPlanID");
        $actorID       = $this->ReadPropertyInteger("ID_Aktor");
        $frostschutz   = $this->ReadPropertyFloat("FrostProtection");

        if ($Message === VM_UPDATE && ($SenderID === $heatingVarID || $SenderID === $vacationVarID)) {
            $heatingActive  = GetValue($heatingVarID);
            $vacationActive = GetValue($vacationVarID);

            // 2.1 Heizungs-Status geändert
            if ($SenderID === $heatingVarID) {
                // A: Heizung aus (kein Urlaub) → Backup + Frostschutz
                if ($heatingActive === false && $vacationActive === false) {
                    if ($actorID > 0 && IPS_VariableExists($actorID)) {
                        $tempVarID1   = $this->GetIDForIdent("set_heating_temperature");
                        $currentValue = ($tempVarID1 > 0 ? GetValue($tempVarID1) : 0.0);
                        if ($currentValue !== $frostschutz) {
                            $this->WriteAttributeFloat("BackupActorSollTemp", $currentValue);
                            IPS_LogMessage("Raumregelung", "Backupwert gesichert (Heizung aus): {$currentValue}");
                        } else {
                            IPS_LogMessage("Raumregelung", "Kein Backup: Sliderwert ist bereits Frostschutz ({$currentValue})");
                        }

                        RequestAction($actorID, $frostschutz);
                        $this->SetValue("set_heating_temperature", $frostschutz);
                        IPS_SetDisabled($actorID, true);
                    }
                }
                // B: Heizung an (kein Urlaub) → Restore
                elseif ($heatingActive === true && $vacationActive === false) {
                    if ($actorID > 0 && IPS_VariableExists($actorID)) {
                        $backup = $this->ReadAttributeFloat("BackupActorSollTemp");
                        if (!is_null($backup)) {
                            RequestAction($actorID, $backup);
                            $this->SetValue("set_heating_temperature", $backup);
                            IPS_LogMessage("Raumregelung", "Aktor {$actorID} auf gesicherten Wert {$backup} zurückgesetzt.");
                            IPS_SetDisabled($actorID, false);
                        }
                    }
                }
            }

            // 2.2 Urlaubs-Status geändert
            if ($SenderID === $vacationVarID) {
                // C: Urlaub an (Heizung an) → Backup + Frostschutz
                if ($vacationActive === true && $heatingActive === true) {
                    if ($actorID > 0 && IPS_VariableExists($actorID)) {
                        $tempVarID1   = $this->GetIDForIdent("set_heating_temperature");
                        $currentValue = ($tempVarID1 > 0 ? GetValue($tempVarID1) : 0.0);
                        if ($currentValue !== $frostschutz) {
                            $this->WriteAttributeFloat("BackupActorSollTemp", $currentValue);
                            IPS_LogMessage("Raumregelung", "Backupwert gesichert (Urlaub an): {$currentValue}");
                        } else {
                            IPS_LogMessage("Raumregelung", "Kein Backup: Sliderwert ist bereits Frostschutz ({$currentValue})");
                        }

                        RequestAction($actorID, $frostschutz);
                        $this->SetValue("set_heating_temperature", $frostschutz);
                        IPS_SetDisabled($actorID, true);
                    }
                }
                // D: Urlaub aus (Heizung an) → Restore
                elseif ($vacationActive === false && $heatingActive === true) {
                    if ($actorID > 0 && IPS_VariableExists($actorID)) {
                        $backup = $this->ReadAttributeFloat("BackupActorSollTemp");
                        if (!is_null($backup)) {
                            RequestAction($actorID, $backup);
                            $this->SetValue("set_heating_temperature", $backup);
                            IPS_LogMessage("Raumregelung", "Aktor {$actorID} auf gesicherten Wert {$backup} zurückgesetzt.");
                            IPS_SetDisabled($actorID, false);
                        }
                    }
                }
            }

            // Slider deaktivieren/aktivieren (Heizung AUS ODER Urlaub AN → deaktiviert)
            $disable = (!$heatingActive || $vacationActive);

            if (($id = $this->GetIDForIdent("set_heating_temperature")) !== false && IPS_VariableExists($id)) {
                IPS_SetDisabled($id, $disable);
            }
            if (($id = $this->GetIDForIdent("set_lowering_temperature")) !== false && IPS_VariableExists($id)) {
                IPS_SetDisabled($id, $disable);
            }

            // === NEU: Aktor synchron zu den Slidern sperren/freigeben ===
            if ($actorID > 0 && IPS_VariableExists($actorID)) {
                IPS_SetDisabled($actorID, $disable);
            }

            // 3. Wochenplan an/aus
            if ($planID > 0 && IPS_EventExists($planID)) {
                if ($vacationActive || !$heatingActive) {
                    IPS_SetEventActive($planID, false);
                    IPS_LogMessage("Raumregelung", "Wochenplan (ID {$planID}) deaktiviert.");
                } else {
                    IPS_SetEventActive($planID, true);
                    IPS_LogMessage("Raumregelung", "Wochenplan (ID {$planID}) aktiviert.");
                    $this->executeCurrentPlanAction($planID); // aktuelle Planaktion ausführen
                }
            }

            // 4. Heizphase aktualisieren
            $this->updateHeatingPhaseState($planID);
            return;
        }

        // 5. Wochenplan-Events (Gruppen/Action/Update) neu triggern
        if (
            $Message === EM_UPDATE
            || $Message === EM_CHANGESCHEDULEGROUP
            || $Message === EM_CHANGESCHEDULEGROUPPOINT
            || $Message === EM_CHANGESCHEDULEACTION
        ) {
            if ($SenderID === $planID) {
                // Marker löschen → ab jetzt Zeitlogik maßgeblich
                $this->WriteAttributeInteger("LastPlanAction", -1);
                $this->executeCurrentPlanAction($planID);
                $this->updateHeatingPhaseState($planID);
            }
        }

        // === Externe Änderungen am Aktor (ID_Aktor):
        //     - Bei Heizung AN & Urlaub AUS → Werte ins WebFront spiegeln
        //     - Bei Heizung AUS ODER Urlaub AN → sofort auf Frostschutz zurücksetzen
        if ($Message === VM_UPDATE && $SenderID === $actorID) {
            // Eigene Echos kurzzeitig ignorieren
            if ($this->isRecentActorEchoFromModule(2)) {
                $this->resetActorWriteMark(); // einmalig löschen
                return;
            }

        $heatingActive  = ($heatingVarID  > 0) ? GetValue($heatingVarID)  : true;
        $vacationActive = ($vacationVarID > 0) ? GetValue($vacationVarID) : false;

        // Bei Heizung AUS oder Urlaub AN: jede externe Aktor-Änderung sofort auf Frostschutz zurücksetzen
        if ($vacationActive || !$heatingActive) {
            $this->markActorWriteFromModule();
            RequestAction($actorID, $frostschutz);

            $heatVarID = $this->GetIDForIdent("set_heating_temperature");
            if ($heatVarID && IPS_VariableExists($heatVarID)) {
                $this->SetValue("set_heating_temperature", $frostschutz);
            }

            IPS_LogMessage(
                "Raumregelung",
                "Externe Aktor-Änderung verworfen (Heizung AUS/Urlaub AN). Zurückgesetzt auf Frostschutz {$frostschutz}°C."
            );
            return; // nichts weiter spiegeln
        }




            $actorValue = (float)GetValue($actorID);

            $action    = $this->getCurrentPlanActionId(); // -1, 0, 1
            $heatVarID = $this->GetIDForIdent("set_heating_temperature");
            $lowVarID  = $this->GetIDForIdent("set_lowering_temperature");

            $heating = ($heatVarID && IPS_VariableExists($heatVarID)) ? (float)GetValue($heatVarID) : 0.0;

            switch ($action) {
                case 0: // Heizen -> nur Heating spiegeln
                    if ($heatVarID && IPS_VariableExists($heatVarID)) {
                        if (abs($actorValue - $heating) > 0.05) {
                            $this->SetValue("set_heating_temperature", $actorValue);
                        }
                    }
                    break;

                case 1: // Absenken -> Lowering als Differenz (heating - actor)
                    if ($lowVarID && IPS_VariableExists($lowVarID)) {
                        $newLowering     = max(0.0, $heating - $actorValue);
                        $newLowering     = min($newLowering, 5.0);
                        $currentLowering = (float)GetValue($lowVarID);
                        if (abs($newLowering - $currentLowering) > 0.05) {
                            $this->SetValue("set_lowering_temperature", $newLowering);
                        }
                    }
                    break;

                default: // -1: konservativ -> nur Heating spiegeln
                    if ($heatVarID && IPS_VariableExists($heatVarID)) {
                        if (abs($actorValue - $heating) > 0.05) {
                            $this->SetValue("set_heating_temperature", $actorValue);
                        }
                    }
                    break;
            }

            return; // Fertig behandelt
        }

        // 6. Änderungen an Fenster-/Türsensoren behandeln
        $entries   = json_decode($this->ReadPropertyString('window_sensor'), true);
        $sensorIDs = is_array($entries) ? array_column($entries, 'InstanceID') : [];

        $heatingActive  = GetValue($heatingVarID);
        $vacationActive = GetValue($vacationVarID);

        // Abbrechen, wenn kein relevanter Sensor oder Heizung aus / Urlaub an
        if ($Message !== VM_UPDATE || !in_array($SenderID, $sensorIDs) || !$heatingActive || $vacationActive) {
            parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);
            return;
        }

        // überprüfen, ob mind. ein Fenster offen ist
        $anyWindowOpen = false;
        foreach ($sensorIDs as $sensorID) {
            if (GetValue($sensorID)) {
                $anyWindowOpen = true;
                break;
            }
        }

        if ($anyWindowOpen) {
            $this->SetValue("windowdoor_status", true);
            IPS_LogMessage("Raumregelung", "Mindestens ein Fenster ist geöffnet: Status auf true gesetzt.");

            // Timer starten
            $delay = $this->ReadPropertyFloat('windowdoor_reporting_delay');
            $this->SetTimerInterval("WindowOpenTimer", intval($delay * 1000));
            IPS_LogMessage("Raumregelung", "Fenster geöffnet: Timer gestartet für {$delay} Sekunden.");
        } else {
            // Alle Fenster geschlossen
            $this->SetValue("windowdoor_status", false);
            IPS_LogMessage("Raumregelung", "Alle Fenster geschlossen: Status auf false gesetzt.");

            // Plan-Zielwert wiederherstellen
            $target  = $this->getTargetSetpointForCurrentPhase();
            $actorID = $this->ReadPropertyInteger("ID_Aktor");
            if ($actorID > 0 && IPS_VariableExists($actorID)) {
                $this->markActorWriteFromModule();
                RequestAction($actorID, $target);
                IPS_LogMessage('Raumregelung', "Alle Fenster geschlossen: stelle Plan-Ziel {$target}°C wieder her");
            }

            // Timer stoppen
            $this->SetTimerInterval("WindowOpenTimer", 0);
        }
    }

    // Ermittelt die aktuell aktive ActionID im Wochenplan
    private function getActivePlanActionId(int $planId)
    {
        if ($planId <= 0 || !IPS_EventExists($planId)) {
            return false;
        }

        $e             = IPS_GetEvent($planId);
        $actionID      = false;
        $currentSecond = date("H") * 3600 + date("i") * 60 + date("s");
        $latestSec     = -1;

        // Zuerst heute prüfen
        foreach ($e['ScheduleGroups'] as $g) {
            if ($g['Days'] & (2 ** (date("N") - 1))) {
                foreach ($g['Points'] as $p) {
                    $sec = $p['Start']['Hour'] * 3600 + $p['Start']['Minute'] * 60 + $p['Start']['Second'];
                    if ($currentSecond >= $sec && $sec > $latestSec) {
                        $latestSec = $sec;
                        $actionID  = $p['ActionID'];
                    }
                }
            }
        }

        // Wenn heute noch keine passende Aktion lief, suche gestern nach der letzten
        if ($actionID === false) {
            $latestSec = -1;
            $yesterday = (date("N") - 2 + 7) % 7;
            foreach ($e['ScheduleGroups'] as $g) {
                if ($g['Days'] & (2 ** $yesterday)) {
                    foreach ($g['Points'] as $p) {
                        $sec = $p['Start']['Hour'] * 3600 + $p['Start']['Minute'] * 60 + $p['Start']['Second'];
                        if ($sec > $latestSec) {
                            $latestSec = $sec;
                            $actionID  = $p['ActionID'];
                        }
                    }
                }
            }
        }

        return $actionID;
    }

    private function getCurrentPlanActionId() : int
    {
        $planID = $this->ReadAttributeInteger("HeatingPlanID");
        if ($planID > 0 && IPS_EventExists($planID)) {
            $a = $this->getActivePlanActionId($planID);
            if ($a !== false && ($a === 0 || $a === 1)) {
                return (int)$a;
            }
        }
        // Kein Plan oder andere Phase (Frostschutz/aus/undefiniert)
        return -1;
    }

    private function getTargetSetpointForCurrentPhase(): float
    {
        $heatVarID = $this->GetIDForIdent("set_heating_temperature");
        $lowVarID  = $this->GetIDForIdent("set_lowering_temperature");

        $heating  = ($heatVarID && IPS_VariableExists($heatVarID)) ? (float)GetValue($heatVarID) : 0.0;
        $lowering = ($lowVarID && IPS_VariableExists($lowVarID))   ? (float)GetValue($lowVarID)  : 0.0;

        $action = $this->getCurrentPlanActionId(); // -1, 0, 1
        if ($action === 1) {
            return max(0.0, $heating - $lowering);   // Absenkphase
        }
        return $heating;                              // Heizen oder konservativ
    }

    // Echo-Schutz markieren, wenn das Modul den Aktor setzt
    private function markActorWriteFromModule(): void {
        $this->WriteAttributeString("LastActorWriteSource", "module");
        $this->WriteAttributeInteger("LastActorWriteTime", time());
    }

    // Echo-Schutz: VM_UPDATE kurz nach unserem Write ignorieren
    private function isRecentActorEchoFromModule(int $graceSec = 2): bool {
        $src  = $this->ReadAttributeString("LastActorWriteSource");
        $when = $this->ReadAttributeInteger("LastActorWriteTime");
        return ($src === "module" && (time() - $when) <= $graceSec);
    }

    // Echo-Schutz zurücksetzen
    private function resetActorWriteMark(): void {
        $this->WriteAttributeString("LastActorWriteSource", "");
    }

    // Führt die aktuelle Plan-Aktion aus, setzt Marker und aktualisiert die Anzeige sofort
    private function executeCurrentPlanAction(int $planID)
    {
        if ($planID <= 0 || !IPS_EventExists($planID)) {
            return;
        }

        // Zusatz: Wenn Heizung aus oder Urlaub an, dann nichts tun
        $heatingVarID   = $this->ReadAttributeInteger("HeatingStatusVarID");
        $vacationVarID  = $this->ReadAttributeInteger("VacationStatusVarID");
        $heatingActive  = ($heatingVarID  > 0) ? GetValue($heatingVarID)  : true;
        $vacationActive = ($vacationVarID > 0) ? GetValue($vacationVarID) : false;
        if (!$heatingActive || $vacationActive) {
            return;
        }

        $event    = IPS_GetEvent($planID);
        $actionID = $this->getActivePlanActionId($planID); // 0/1 oder false

        if ($actionID !== false && isset($event['ScheduleActions'][$actionID])) {
            $a = $event['ScheduleActions'][$actionID];
            $script = $a['ScriptText'] ?: ($a['ActionParameters']['SCRIPT'] ?? "");
            if ($script !== "") {
                // Aktionsskript ausführen (stellt den Aktor)
                IPS_RunScriptText($script);
                IPS_LogMessage("Raumregelung", "ExecutePlanAction: Aktion {$actionID} ausgeführt.");

                // Phase SOFORT anzeigen + manuell gesetzte Phase merken
                $this->WriteAttributeInteger("LastPlanAction", (int)$actionID);

                $varID = @$this->GetIDForIdent("actual_heating_phase");
                if ($varID !== false) {
                    $this->SetValue("actual_heating_phase", (int)$actionID); // 0=Heizen, 1=Absenken
                }
            } else {
                IPS_LogMessage("Raumregelung", "ExecutePlanAction: Kein Script für Aktion {$actionID}.");
            }
        }
    }

    // Erzeugt bzw. aktualisiert den Wochenplan je nach Auswahl
    private function rebuildWeeklyScheduleForSelection(int $selection)
    {
        $actorID    = $this->ReadPropertyInteger("ID_Aktor");
        $tempVarID1 = $this->GetIDForIdent("set_heating_temperature");
        $tempVarID2 = $this->GetIDForIdent("set_lowering_temperature");

        // 1) Alten Plan löschen, falls vorhanden
        $existingPlanID = $this->ReadAttributeInteger("HeatingPlanID");
        if ($existingPlanID > 0 && IPS_EventExists($existingPlanID)) {
            IPS_DeleteEvent($existingPlanID);
            $this->WriteAttributeInteger("HeatingPlanID", 0);
            IPS_LogMessage("Raumregelung", "Alter Wochenplan (ID {$existingPlanID}) gelöscht.");
            $this->updateLoweringVisibility(); // nach Löschung sofort ausblenden
        }

        // 2) Auswahl = 0 → keinen neuen Plan anlegen
        if ($selection === 0) {
            $this->updateLoweringVisibility(); // kein neuer Plan -> ausblenden
            return;
        }

        // 3) Neues Schedule-Event anlegen (Typ 2 = Zeitplan)
        $heatingPlan = IPS_CreateEvent(2);
        $this->WriteAttributeInteger("HeatingPlanID", $heatingPlan);
        IPS_SetParent($heatingPlan, $this->InstanceID);
        IPS_SetIdent($heatingPlan, "HeatingPlan");
        IPS_SetName($heatingPlan, "Heizplan");
        IPS_SetEventActive($heatingPlan, true);
        IPS_SetPosition($heatingPlan, 4);
        IPS_SetIcon($heatingPlan, "calendar-clock");
        $this->updateLoweringVisibility(); // neuer Plan -> einblenden

        // 4) Aktionen anlegen – mit Echo-Schutz-Präfix
        $iid = $this->InstanceID;
        IPS_SetEventScheduleAction(
            $heatingPlan,
            0,
            "Heizen",
            0xFF0000,
            "IPS_RequestAction($iid, \"__MarkActorWriteByModule\", 0); RequestAction({$actorID}, GetValue({$tempVarID1}));"
        );
        IPS_SetEventScheduleAction(
            $heatingPlan,
            1,
            "Absenken",
            0xFF7F00,
            "IPS_RequestAction($iid, \"__MarkActorWriteByModule\", 0); RequestAction({$actorID}, GetValue({$tempVarID1}) - GetValue({$tempVarID2}));"
        );

        // 5) Gruppen/Points je nach Auswahl
        switch ($selection) {
            case 1:
                // Eine Gruppe für alle Tage
                IPS_SetEventScheduleGroup($heatingPlan, 0, 127);
                IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 0,  0,  0, 0, 0);  // 00:00 -> (Aktion 0)
                IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 1,  6,  0, 0, 1);  // 06:00 -> Heizen
                IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 2, 20,  0,  0, 0); // 20:00 -> Absenken
                break;

            case 2:
                // Mo–Fr + Sa–So getrennt
                IPS_SetEventScheduleGroup($heatingPlan, 0, 31); // Mo–Fr
                IPS_SetEventScheduleGroup($heatingPlan, 1, 96); // Sa–So

                IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 0,  0,  0, 0, 0);
                IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 1,  6,  0, 0, 1);
                IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 2, 20,  0, 0, 0);

                IPS_SetEventScheduleGroupPoint($heatingPlan, 1, 0,  0,  0, 0, 0);
                IPS_SetEventScheduleGroupPoint($heatingPlan, 1, 1,  8,  0, 0, 1);
                IPS_SetEventScheduleGroupPoint($heatingPlan, 1, 2, 22,  0, 0, 0);
                break;

            case 3:
                // Jede Wochengruppe einzeln
                for ($i = 0; $i < 7; $i++) {
                    IPS_SetEventScheduleGroup($heatingPlan, $i, (1 << $i));
                    IPS_SetEventScheduleGroupPoint($heatingPlan, $i, 0,  0,  0, 0, 0);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, $i, 1,  6,  0, 0, 1);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, $i, 2, 20,  0, 0, 0);
                }
                break;

            default:
                // keine Gruppen
                break;
        }

        // 6) Auf Änderungen am Plan lauschen
        $this->RegisterMessage($heatingPlan, EM_UPDATE);
        $this->RegisterMessage($heatingPlan, EM_CHANGESCHEDULEGROUP);
        $this->RegisterMessage($heatingPlan, EM_CHANGESCHEDULEGROUPPOINT);
        $this->RegisterMessage($heatingPlan, EM_CHANGESCHEDULEACTION);

        IPS_LogMessage("Raumregelung", "Neuer Wochenplan angelegt (ID {$heatingPlan}).");
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case "__MarkActorWriteByModule":
                $this->markActorWriteFromModule();
                return;

            case "set_heating_temperature":
                $actorID = $this->ReadPropertyInteger("ID_Aktor");
                if ($actorID > 0 && IPS_VariableExists($actorID)) {
                    $this->SetValue("set_heating_temperature", $Value);

                    // Phasenlogik: nur senden, wenn nicht Action 1 aktiv ist
                    $action = $this->getCurrentPlanActionId(); // -1, 0, 1
                    if ($action === 1) {
                        IPS_LogMessage("Raumregelung", "Heating-Änderung geblockt (Action 1 aktiv).");
                        break;
                    }

                    $this->markActorWriteFromModule();
                    RequestAction($actorID, $Value);
                }
                break;

            case "set_lowering_temperature":
                $actorID = $this->ReadPropertyInteger("ID_Aktor");
                $this->SetValue("set_lowering_temperature", $Value);

                $action = $this->getCurrentPlanActionId(); // -1, 0, 1
                if ($action === 0) {
                    IPS_LogMessage("Raumregelung", "Lowering-Änderung geblockt (Action 0 aktiv).");
                    break;
                }

                if ($action === 1) {
                    $heatVarID = $this->GetIDForIdent("set_heating_temperature");
                    $heat      = ($heatVarID && IPS_VariableExists($heatVarID)) ? (float)GetValue($heatVarID) : 0.0;
                    $target    = $heat - (float)$Value;

                    if ($actorID > 0 && IPS_VariableExists($actorID)) {
                        $this->markActorWriteFromModule();
                        RequestAction($actorID, $target);
                    }
                    break;
                }

                // action === -1 (konservativ: nicht senden)
                IPS_LogMessage("Raumregelung", "Lowering geändert, aber keine passende Action aktiv – kein Senden.");
                break;

            case "WindowOpenTimer":
                $this->executeWindowOpenLowering();
                break;
        }
    }

    /**
     * Ermittelt basierend auf Heizungs-/Urlaubs-Status und dem Wochenplan die
     * aktuelle Heizphase und schreibt sie in die Variable "actual_heating_phase".
     */
    // Aktualisiert die Anzeigefase unter Beachtung von Heizung/Urlaub + Marker
    private function updateHeatingPhaseState(int $planID)
    {
        $heatingVarID  = $this->ReadAttributeInteger("HeatingStatusVarID");
        $vacationVarID = $this->ReadAttributeInteger("VacationStatusVarID");
        $heatingActive  = ($heatingVarID  > 0) ? GetValue($heatingVarID)  : true;
        $vacationActive = ($vacationVarID > 0) ? GetValue($vacationVarID) : false;

        $phase = 3; // default "-" = aus

        if ($heatingActive && !$vacationActive) {
            // zuerst manuellen Marker beachten
            $last = @$this->ReadAttributeInteger("LastPlanAction");
            if ($last === 0 || $last === 1) {
                $phase = $last;
            } else {
                // dann zeitbasierte Action
                if ($planID > 0 && IPS_EventExists($planID)) {
                    $actionID = $this->getActivePlanActionId($planID);
                    $phase    = ($actionID !== false ? (int)$actionID : 3);
                }
            }
        } elseif ($heatingActive && $vacationActive) {
            $phase = 2; // Frostschutz
        }

        $varID = @$this->GetIDForIdent("actual_heating_phase");
        if ($varID !== false) {
            $this->SetValue("actual_heating_phase", $phase);
            IPS_LogMessage("Raumregelung", "actual_heating_phase → {$phase}");
        }
    }

    public function executeWindowOpenLowering()
    {
        $entries   = json_decode($this->ReadPropertyString('window_sensor'), true);
        $sensorIDs = is_array($entries) ? array_column($entries, 'InstanceID') : [];

        // Wenn noch ein Fenster geöffnet ist → Absenkung
        foreach ($sensorIDs as $id) {
            if (GetValue($id)) {
                $openTemp = $this->ReadPropertyFloat('windowdoor_temperature');
                $actorID  = $this->ReadPropertyInteger("ID_Aktor");
                if ($actorID > 0 && IPS_VariableExists($actorID)) {
                    $this->markActorWriteFromModule();
                    RequestAction($actorID, $openTemp);
                    // Slider NICHT verändern
                }
                break; // nach dem ersten offenen Fenster reicht
            }
        }

        // Timer wieder stoppen (einmalig ausführen)
        $this->SetTimerInterval("WindowOpenTimer", 0);
    }



    // Blendet die Variable "set_lowering_temperature" ein/aus je nach vorhandenem Wochenplan
    private function updateLoweringVisibility(): void
    {
        $varID  = @$this->GetIDForIdent("set_lowering_temperature");
        if ($varID === false) {
            return; // nicht angelegt
        }

        // Einblenden nur, wenn ein echter Wochenplan existiert
        $planID   = $this->ReadAttributeInteger("HeatingPlanID");
        $hasPlan  = ($planID > 0) && IPS_EventExists($planID);

        IPS_SetHidden($varID, !$hasPlan); // kein Plan => ausblenden
    }


}

?>
