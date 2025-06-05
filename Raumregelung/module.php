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

        // a) ID des physischen Aktors (Variable)
        $this->RegisterPropertyInteger("ID_Aktor", 0);

        // b) ID der Ist-Temperatur-Variable
        $this->RegisterPropertyInteger("Is_Temperature", 0);

        // c) Auswahl des Wochenplans (0 = aus, 1 … 3 = je nach Gruppen‐Logik)
        $this->RegisterPropertyInteger("Weekly_Schedule_Selection", 0);

        // d) ID von Modul 1 (Einstellungen), um über MessageSink die Booleans zu bekommen
        $this->RegisterPropertyInteger("SettingsModuleID", 0);

        // e) Frostschutz‐Temperatur (z.B. 5 °C)
        $this->RegisterPropertyFloat("FrostProtection", 5);

        // f) Soll die Variable für die aktuelle Heizphase angezeigt werden?
        $this->RegisterPropertyBoolean("Actual_Heating_Phase", false);

        ##############################
        // 3. Attribute zum Speichern alter Werte

        // a) Backup-Sollwert für den Aktor
        $this->RegisterAttributeFloat("BackupActorSollTemp", 0.0);

        // b) Alte Auswahl des Wochenplans
        $this->RegisterAttributeInteger("WeeklySelection_Old", 0);

        // c) ID des erstellten Wochenplan-Events
        $this->RegisterAttributeInteger("HeatingPlanID", 0);

        // d) IDs der Boolean-Variablen aus Modul 1
        $this->RegisterAttributeInteger("HeatingStatusVarID", 0);
        $this->RegisterAttributeInteger("VacationStatusVarID", 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        ##############################
        // 1. Wochenplan anlegen/aktualisieren, falls sich die Auswahl geändert hat
        $currentPlan = $this->ReadPropertyInteger("Weekly_Schedule_Selection");
        $oldPlan     = $this->ReadAttributeInteger("WeeklySelection_Old");
        if ($currentPlan !== $oldPlan) {
            $this->CreateOrUpdateWeeklySchedule($currentPlan);
            $this->WriteAttributeInteger("WeeklySelection_Old", $currentPlan);
        }

        $planID = $this->ReadAttributeInteger("HeatingPlanID");
        if ($planID > 0 && IPS_EventExists($planID)) {
            // Registriere alle relevanten Nachrichten, damit Änderungen am Wochenplan erkannt werden
            $this->RegisterMessage($planID, EM_UPDATE);
            $this->RegisterMessage($planID, EM_CHANGESCHEDULEGROUP);
            $this->RegisterMessage($planID, EM_CHANGESCHEDULEGROUPPOINT);
            $this->RegisterMessage($planID, EM_CHANGESCHEDULEACTION);
        }



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
        // 4. Anzeige der aktuellen Heizphase, falls aktiviert
        if ($this->ReadPropertyBoolean("Actual_Heating_Phase")) {
            if (@$this->GetIDForIdent("actual_heating_phase") === false) {
                $id = $this->RegisterVariableInteger(
                    "actual_heating_phase",
                    $this->Translate("Heating phase"),
                    "SS.ETR.Heizphase",
                    5
                );
                IPS_SetIcon($id, "calendar-range");
                IPS_LogMessage("Raumregelung", "Variable actual_heating_phase angelegt.");
            }
        } else {
            $id = @$this->GetIDForIdent("actual_heating_phase");
            if ($id !== false) {
                IPS_DeleteVariable($id);
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
    
            // 2.1 Heizungs-Status geändert (Sender ist heatingVarID)
            if ($SenderID === $heatingVarID) {
                // Fall A: Heizung wurde auf "aus" gesetzt (und kein Urlaub aktiv) → Backup + Frostschutz
                if ($heatingActive === false && $vacationActive === false) {
                    if ($actorID > 0 && IPS_VariableExists($actorID)) {
                        // ===== Änderung: Backup aus Modul-Slider, nur wenn Slider ≠ Frostschutz =====
                        $tempVarID1   = $this->GetIDForIdent("set_heating_temperature");
                        $currentValue = ($tempVarID1 > 0 ? GetValue($tempVarID1) : 0.0);
                        if ($currentValue !== $frostschutz) {
                            $this->WriteAttributeFloat("BackupActorSollTemp", $currentValue);
                            IPS_LogMessage("Raumregelung", "Backupwert gesichert (Heizung aus): {$currentValue}");
                        } else {
                            IPS_LogMessage("Raumregelung", "Kein Backup: Sliderwert ist bereits Frostschutz ({$currentValue})");
                        }
    
                        // Frostschutz setzen
                        RequestAction($actorID, $frostschutz);
                        $this->SetValue("set_heating_temperature", $frostschutz);
                        IPS_SetDisabled($actorID, true);
                    }
                }
                // Fall B: Heizung wurde auf "an" gesetzt (und kein Urlaub aktiv) → Restore des Backups
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
                // Hinweis: Heizung "an" während Urlaub aktiv → keine Aktion hier
            }
    
            // 2.2 Urlaubs-Status geändert (Sender ist vacationVarID)
            if ($SenderID === $vacationVarID) {
                // Fall C: Urlaub wurde eingeschaltet (und Heizung ist an) → Backup + Frostschutz
                if ($vacationActive === true && $heatingActive === true) {
                    if ($actorID > 0 && IPS_VariableExists($actorID)) {
                        // ===== Änderung: Backup aus Modul-Slider, nur wenn Slider ≠ Frostschutz =====
                        $tempVarID1   = $this->GetIDForIdent("set_heating_temperature");
                        $currentValue = ($tempVarID1 > 0 ? GetValue($tempVarID1) : 0.0);
                        if ($currentValue !== $frostschutz) {
                            $this->WriteAttributeFloat("BackupActorSollTemp", $currentValue);
                            IPS_LogMessage("Raumregelung", "Backupwert gesichert (Urlaub an): {$currentValue}");
                        } else {
                            IPS_LogMessage("Raumregelung", "Kein Backup: Sliderwert ist bereits Frostschutz ({$currentValue})");
                        }
    
                        // Frostschutz setzen
                        RequestAction($actorID, $frostschutz);
                        $this->SetValue("set_heating_temperature", $frostschutz);
                        IPS_SetDisabled($actorID, true);
                    }
                }
                // Fall D: Urlaub wurde ausgeschaltet (und Heizung ist an) → Restore des Backups
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
                // Hinweis: Urlaub "aus" während Heizung aus → keine Aktion hier
            }
    
            // 3. Wochenplan an/aus schalten (unabhängig von Backup/Restore)
            if ($planID > 0 && IPS_EventExists($planID)) {
                if ($vacationActive || !$heatingActive) {
                    IPS_SetEventActive($planID, false);
                    IPS_LogMessage("Raumregelung", "Wochenplan (ID {$planID}) deaktiviert.");
                } else {
                    IPS_SetEventActive($planID, true);
                    IPS_LogMessage("Raumregelung", "Wochenplan (ID {$planID}) aktiviert.");
                    // Direkt aktuelle Planaktion ausführen
                    $this->ExecuteActualPlanAction($planID);
                }
            }
    
            // 4. Heizphase aktualisieren (falls die Variable existiert)
            $this->UpdateHeatingPhaseVariable($planID);
    
            return;
        }
    
        // 5. Wochenplan-Events (Gruppen/Action/Update) neu triggern
        if (
            $Message === EM_UPDATE
            || $Message === EM_CHANGESCHEDULEGROUP
            || $Message === EM_CHANGESCHEDULEGROUPPOINT
            || $Message === EM_CHANGESCHEDULEACTION
        ) {
            $sourcePlanID = $this->ReadAttributeInteger("HeatingPlanID");
            if ($SenderID === $sourcePlanID) {
                // 5.1: Ausführen der gerade aktiven Plan‐Action
                $this->ExecuteActualPlanAction($sourcePlanID);
                // 5.2: Heizphase‐Variable aktualisieren
                $this->UpdateHeatingPhaseVariable($sourcePlanID);
            }
        }
    
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);
    }
    

    // Ermittelt die aktuell aktive ActionID im Wochenplan
    private function GetActiveAction(int $wochenplanID)
    {
        if ($wochenplanID <= 0 || !IPS_EventExists($wochenplanID)) {
            return false;
        }

        $e             = IPS_GetEvent($wochenplanID);
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

    // Führt die aktuelle Plan-Aktion aus (und schreibt Log)
    private function ExecuteActualPlanAction(int $planID)
    {
        if ($planID <= 0 || !IPS_EventExists($planID)) {
            return;
        }

        // Zusatz: Wenn Heizung aus oder Urlaub an, dann nichts tun
        $heatingVarID  = $this->ReadAttributeInteger("HeatingStatusVarID");
        $vacationVarID = $this->ReadAttributeInteger("VacationStatusVarID");
        $heatingActive  = ($heatingVarID  > 0) ? GetValue($heatingVarID)  : true;
        $vacationActive = ($vacationVarID > 0) ? GetValue($vacationVarID) : false;
        if (!$heatingActive || $vacationActive) {
            return;
        }

        $event    = IPS_GetEvent($planID);
        $actionID = $this->GetActiveAction($planID);

        if ($actionID !== false && isset($event['ScheduleActions'][$actionID])) {
            $a = $event['ScheduleActions'][$actionID];
            // Skripttext auslesen (entweder ScriptText oder ActionParameters['SCRIPT'])
            $script = $a['ScriptText'] ?: ($a['ActionParameters']['SCRIPT'] ?? "");
            if ($script !== "") {
                IPS_RunScriptText($script);
                IPS_LogMessage("Raumregelung", "ExecutePlanAction: Aktion {$actionID} ausgeführt.");
            } else {
                IPS_LogMessage("Raumregelung", "ExecutePlanAction: Kein Script für Aktion {$actionID}.");
            }
        }
    }

    // Erzeugt bzw. aktualisiert den Wochenplan je nach Auswahl
    private function CreateOrUpdateWeeklySchedule(int $Value)
    {
        $actorID    = $this->ReadPropertyInteger("ID_Aktor");
        $tempVarID1 = $this->GetIDForIdent("set_heating_temperature");
        $tempVarID2 = $this->GetIDForIdent("set_lowering_temperature");

        // 1. Alten Plan löschen, falls vorhanden
        $existingPlanID = $this->ReadAttributeInteger("HeatingPlanID");
        if ($existingPlanID > 0 && IPS_EventExists($existingPlanID)) {
            IPS_DeleteEvent($existingPlanID);
            $this->WriteAttributeInteger("HeatingPlanID", 0);
            IPS_LogMessage("Raumregelung", "Alter Wochenplan (ID {$existingPlanID}) gelöscht.");
        }

        // 2. Wenn Wert = 0, dann gar keinen neuen Plan anlegen
        if ($Value === 0) {
            return;
        }

        // 3. Neues Schedule-Event anlegen (Typ 2 = Zeitplan)
        $heatingPlan = IPS_CreateEvent(2);
        $this->WriteAttributeInteger("HeatingPlanID", $heatingPlan);
        IPS_SetParent($heatingPlan, $this->InstanceID);
        IPS_SetIdent($heatingPlan, "HeatingPlan");
        IPS_SetName($heatingPlan, "Heizplan");
        IPS_SetEventActive($heatingPlan, true);
        IPS_SetPosition($heatingPlan, 4);
        IPS_SetIcon($heatingPlan, "calendar-clock");

        // 4. Wochenplan Aktionen anlegen
        IPS_SetEventScheduleAction(
            $heatingPlan,
            0,
            "Heizen",
            0xFF0000,
            "RequestAction({$actorID}, GetValue({$tempVarID1}));"
        );
        IPS_SetEventScheduleAction(
            $heatingPlan,
            1,
            "Absenken",
            0xFF7F00,
            "RequestAction({$actorID}, GetValue({$tempVarID1}) - GetValue({$tempVarID2}));"
        );

        // 5. Jetzt erst die Gruppen definieren (je nach Auswahl Value):
        switch ($Value) {
            case 1:
                // Beispiel: nur Gruppe 0 für alle Wochentage
                IPS_SetEventScheduleGroup($heatingPlan, 0, 127);
                IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 0,  0,  0, 0, 0);   // 00:00 Uhr
                IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 1,  6,  0, 0, 1);   // 06:00 Uhr → Heizen
                IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 2, 20,  0, 0, 0);   // 20:00 Uhr → Absenken
                break;

            case 2:
                // Beispiel: Gruppe 0 = Mo–Fr, Gruppe 1 = Sa–So
                IPS_SetEventScheduleGroup($heatingPlan, 0, 31);  // Bitmaske Mo–Fr
                IPS_SetEventScheduleGroup($heatingPlan, 1, 96);  // Bitmaske Sa–So

                // Für Gruppe 0 (Mo–Fr) drei Punkte
                IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 0,  0,  0, 0, 0);
                IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 1,  6,  0, 0, 1);
                IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 2, 20,  0, 0, 0);

                // Für Gruppe 1 (Sa–So) drei Punkte
                IPS_SetEventScheduleGroupPoint($heatingPlan, 1, 0,  0,  0, 0, 0);
                IPS_SetEventScheduleGroupPoint($heatingPlan, 1, 1,  8,  0, 0, 1);
                IPS_SetEventScheduleGroupPoint($heatingPlan, 1, 2, 22,  0, 0, 0);
                break;

            case 3:
                // Beispiel: jede Wochentagsgruppe einzeln (Mo, Di, Mi, … So)
                for ($i = 0; $i < 7; $i++) {
                    IPS_SetEventScheduleGroup($heatingPlan, $i, (1 << $i));
                    IPS_SetEventScheduleGroupPoint($heatingPlan, $i, 0,  0,  0, 0, 0);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, $i, 1,  6,  0, 0, 1);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, $i, 2, 20,  0, 0, 0);
                }
                break;

            default:
                // Falls ganz unerwartet ein anderer Wert kommt, einfach keine Gruppe
                break;
        }

        // ────────────────────────────────────────────────
        // 6. “Richtiges” Skript in die Action-Slots schreiben
        //    (anstatt der leeren Strings von oben)
        IPS_SetEventScheduleAction(
            $heatingPlan,
            0,
            "Heizen",
            0xFF0000,
            "RequestAction({$actorID}, GetValue({$tempVarID1}));"
        );
        IPS_SetEventScheduleAction(
            $heatingPlan,
            1,
            "Absenken",
            0xFF7F00,
            "RequestAction({$actorID}, GetValue({$tempVarID1}) - GetValue({$tempVarID2}));"
        );

        // 7. Auf Änderungen am Plan (Gruppen/Punkte/Action) lauschen
        $this->RegisterMessage($heatingPlan, EM_UPDATE);
        $this->RegisterMessage($heatingPlan, EM_CHANGESCHEDULEGROUP);
        $this->RegisterMessage($heatingPlan, EM_CHANGESCHEDULEGROUPPOINT);
        $this->RegisterMessage($heatingPlan, EM_CHANGESCHEDULEACTION);

        IPS_LogMessage("Raumregelung", "Neuer Wochenplan angelegt (ID {$heatingPlan}).");
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case "set_heating_temperature":
                $actorID = $this->ReadPropertyInteger("ID_Aktor");
                if ($actorID > 0 && IPS_VariableExists($actorID)) {
                    $this->SetValue("set_heating_temperature", $Value);
                    RequestAction($actorID, $Value);
                }
                break;

            case "set_lowering_temperature":
                $actorID = $this->ReadPropertyInteger("ID_Aktor");
                if ($actorID > 0 && IPS_VariableExists($actorID)) {
                    $this->SetValue("set_lowering_temperature", $Value);
                    //RequestAction($actorID, $Value);
                }
                break;
        }
    }

    /**
 * Ermittelt basierend auf Heizungs‐/Urlaubs‐Status und dem Wochenplan die
 * aktuelle Heizphase und schreibt sie in die Variable "actual_heating_phase".
 */
private function UpdateHeatingPhaseVariable(int $planID)
{
    // 1. Heizungs‐ und Urlaubsstatus einlesen
    $heatingVarID  = $this->ReadAttributeInteger("HeatingStatusVarID");
    $vacationVarID = $this->ReadAttributeInteger("VacationStatusVarID");
    $heatingActive  = ($heatingVarID  > 0) ? GetValue($heatingVarID)  : true;
    $vacationActive = ($vacationVarID > 0) ? GetValue($vacationVarID) : false;

    // 2. Default‐Phase = 3 ("aus")
    $phase = 3;

    if ($heatingActive && !$vacationActive) {
        // Wenn Plan existiert, aktuellen Action‐ID bestimmen
        if ($planID > 0 && IPS_EventExists($planID)) {
            $actionID = $this->GetActiveAction($planID);
            $phase    = ($actionID !== false ? $actionID : 0);
        } else {
            // Kein Plan → aus
            $phase = 3;
        }
    }
    elseif ($heatingActive && $vacationActive) {
        // Heizung an, Urlaub an → Frostschutz = Phase 2
        $phase = 2;
    }
    // Sonst: Heizung aus → Phase = 3

    // 3. In die Variable schreiben, falls vorhanden
    $varID = @$this->GetIDForIdent("actual_heating_phase");
    if ($varID !== false) {
        $this->SetValue("actual_heating_phase", $phase);
        IPS_LogMessage("Raumregelung", "actual_heating_phase → {$phase}");
    }
}
}

?>