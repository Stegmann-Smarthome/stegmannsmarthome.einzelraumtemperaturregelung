<?php

class Aktor extends IPSModule
{
    public function Create()
    {
        parent::Create();

        ##############################
        // 1. Soll-Temperatur-Variablen (Slider)
        $this->RegisterVariableFloat(
            "set_heating_temperature",
            $this->Translate("Heating temperature"),
            ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'TEMPLATE' => VARIABLE_TEMPLATE_SLIDER_ROOM_TEMPERATURE, 'USAGE_TYPE' => 0, 'MIN' => 12, 'MAX' => 25],
            1
        );
        $this->EnableAction("set_heating_temperature");

        // 2. Absenk-Temperatur-Variablen (Slider)
        $this->RegisterVariableFloat(
            "set_lowering_temperature",
            $this->Translate("Lowering temperature"),
            ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'TEMPLATE' => VARIABLE_TEMPLATE_SLIDER_ROOM_TEMPERATURE, 'USAGE_TYPE' => 0, 'MIN' => 10, 'MAX' => 25],
            2
        );
        $this->EnableAction("set_lowering_temperature");

        ##############################
        // 2. Eigenschaften für das Konfigurationsformular
        $this->RegisterPropertyInteger("HeatingControlType", 0);
        $this->RegisterPropertyInteger("ID_Aktor", 0);                   // a) ID des physischen Aktors (Variable)
        $this->RegisterPropertyInteger("ID_SwitchAktor", 0);
        $this->RegisterPropertyInteger("Is_Temperature", 0);             // b) ID der Ist-Temperatur-Variable
        $this->RegisterPropertyInteger("Weekly_Schedule_Selection", 0);  // c) Auswahl des Wochenplans
        $this->RegisterPropertyInteger("SettingsModuleID", 0);           // d) ID von Modul 1 (Einstellungen)
        $this->RegisterPropertyFloat("FrostProtection", 5);              // e) Frostschutz-Temperatur
        $this->RegisterPropertyFloat("SwitchHysteresis", 0.0);
        $this->RegisterPropertyBoolean("Invert_SwitchAktor", false);
        $this->RegisterPropertyBoolean("Actual_Heating_Phase", false);   // f) Anzeige Heizphase?
        $this->RegisterPropertyString('window_sensor', json_encode([])); // g) Fenster-/Türsensoren
        $this->RegisterPropertyFloat('windowdoor_temperature', 10.0);    // h) Absenktemp bei Fenster offen
        $this->RegisterPropertyFloat('windowdoor_reporting_delay', 30.0);// i) Meldeverzögerung
        $this->RegisterPropertyBoolean("Windowdoor_Status", false);      // j) Anzeige Kontaktstatus?
        $this->RegisterPropertyBoolean("Show_Override_Button", false);   // k) Override-Schalter anzeigen?
        $this->RegisterPropertyBoolean("Auto_Disable_Override_Last_Lowering", false); // l) Override am letzten Absenkbereich auto deaktivieren

        $this->RegisterPropertyInteger("HeatingBlock_VarID", 0);
        $this->RegisterPropertyBoolean("HeatingBlock_Status", false);

        $this->RegisterPropertyInteger("ForceHeating_VarID", 0);

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
        $this->RegisterAttributeFloat("BackupLoweringTemp", 0.0);  // Backup-Absenk-Soll
        $this->RegisterAttributeString("OverrideDate", "");       // Datum der Aktivierung (YYYYMMDD)

        // Timer Registrierung: ident bleibt "WindowOpenTimer"
        $this->RegisterTimer("WindowOpenTimer", 0, 'IPS_RequestAction(' . $this->InstanceID . ', "WindowOpenTimer", "0");');

        // Marker für zuletzt manuell ausgeführte Wochenplan-Aktion
        $this->RegisterAttributeInteger("LastPlanAction", -1); // -1 = kein manueller Marker, 0/1 = Heizen/Absenken
    }

    // Dynamische anpassung des Konfigurationsformulars der Instanz
    public function GetConfigurationForm()
    {
        $form = @file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'form.json');
        $data = json_decode($form, true);
        if (!is_array($data)) {
            return '';
        }

        $controlType = (int)$this->ReadPropertyInteger('HeatingControlType');

        // 0 = Automatik, 1 = 2-Punkt
        if ($controlType === 0) {
            $hideNames = ['ID_SwitchAktor', 'Invert_SwitchAktor', 'SwitchHysteresis'];
        } else {
            $hideNames = ['ID_Aktor'];
        }

        $filterElements = function (array $elements) use (&$filterElements, $hideNames) {
            $result = [];
            foreach ($elements as $el) {
                if (!is_array($el)) {
                    continue;
                }

                // Einzelelemente mit name direkt entfernen
                if (isset($el['name']) && in_array($el['name'], $hideNames, true)) {
                    continue;
                }

                // Verschachtelte Layouts rekursiv filtern
                if (isset($el['items']) && is_array($el['items'])) {
                    $el['items'] = $filterElements($el['items']);

                    // Spezialfall: RowLayout komplett entfernen, wenn danach keine echten Controls mehr übrig sind
                    if (($el['type'] ?? '') === 'RowLayout') {
                        $hasNonLabel = false;
                        foreach ($el['items'] as $it) {
                            if (($it['type'] ?? '') !== 'Label') {
                                $hasNonLabel = true;
                                break;
                            }
                        }
                        if (!$hasNonLabel) {
                            continue;
                        }

                        // Spacer-Labels ("" caption) entfernen, wenn sie am Ende stehen
                        while (count($el['items']) > 0) {
                            $last = $el['items'][count($el['items']) - 1];
                            if (is_array($last) && ($last['type'] ?? '') === 'Label' && ($last['caption'] ?? '') === '') {
                                array_pop($el['items']);
                                continue;
                            }
                            break;
                        }
                    }
                }

                $result[] = $el;
            }
            return $result;
        };

        if (isset($data['elements']) && is_array($data['elements'])) {
            $data['elements'] = $filterElements($data['elements']);
        }

        return json_encode($data);
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

        // Bestehenden Wochenplan für Events registrieren (falls vorhanden)
        $planID = $this->ReadAttributeInteger("HeatingPlanID");
        if ($planID > 0 && IPS_EventExists($planID)) {
            // Registriere relevante Nachrichten
            $this->RegisterMessage($planID, EM_UPDATE);
            $this->RegisterMessage($planID, EM_CHANGESCHEDULEGROUP);
            $this->RegisterMessage($planID, EM_CHANGESCHEDULEGROUPPOINT);
            $this->RegisterMessage($planID, EM_CHANGESCHEDULEACTION);
        }

        $this->updateLoweringVisibility();

        // Override-Button (erstellen/löschen je nach Konfiguration)
        $showOverride = $this->ReadPropertyBoolean("Show_Override_Button");
        $overrideID = @IPS_GetObjectIDByIdent("manual_override", $this->InstanceID);
        if ($showOverride) {
            if (!$overrideID) {
                $overrideID = $this->RegisterVariableBoolean("manual_override", $this->Translate("Override"), "~Switch", 7);
                $this->EnableAction("manual_override");
            }
        } else {
            if ($overrideID && IPS_VariableExists($overrideID)) {
                @IPS_DeleteVariable($overrideID);
            }
        }

        ##############################
        // 2. MessageSink-Registrierung für Änderungen in Modul 1
        $settingsID    = $this->ReadPropertyInteger("SettingsModuleID");
        $heatingVarID  = 0;
        $vacationVarID = 0;
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

        $heatingBlockVarID = (int)$this->ReadPropertyInteger("HeatingBlock_VarID");
        if ($heatingBlockVarID > 0 && IPS_VariableExists($heatingBlockVarID)) {
            $this->RegisterMessage($heatingBlockVarID, VM_UPDATE);
        }

        $forceHeatingVarID = (int)$this->ReadPropertyInteger("ForceHeating_VarID");
        if ($forceHeatingVarID > 0 && IPS_VariableExists($forceHeatingVarID)) {
            $this->RegisterMessage($forceHeatingVarID, VM_UPDATE);
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

        // Override-Variable initial deaktivieren wenn Heizung aus oder Urlaub an
        $overrideVarID = @$this->GetIDForIdent("manual_override");
        if ($overrideVarID !== false && IPS_VariableExists($overrideVarID)) {
            IPS_SetDisabled($overrideVarID, $disable);
            if ($disable && (bool)GetValue($overrideVarID)) {
                $this->SetValue("manual_override", false);
                $this->WriteAttributeString("OverrideDate", "");
            }
        }

        // === Aktor analog zu den Slidern initial sperren/freigeben ===
        $controlType = (int)$this->ReadPropertyInteger('HeatingControlType');
        if ($controlType === 0) {
            $actorID = $this->ReadPropertyInteger("ID_Aktor");
            if ($actorID > 0 && IPS_VariableExists($actorID)) {
                $disableInit = (!$heatingActiveInit || $vacationActiveInit);
                IPS_SetDisabled($actorID, $disableInit);
            }
        }

        // Externe Heizsperre initial anwenden (falls aktiv)
        $this->applyHeatingBlockState();

        // Externes Heizen erzwingen initial anwenden (falls aktiv)
        $this->applyForceHeatingState();

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
        }

        // Ist-Temperatur-Variable auf VM_UPDATE überwachen
        if ($targetID > 0 && IPS_VariableExists($targetID)) {
            $this->RegisterMessage($targetID, VM_UPDATE);
        }

        ##############################
        // 4.1 Anzeige der aktuellen Heizphase, falls aktiviert
        if ($this->ReadPropertyBoolean("Actual_Heating_Phase")) {
            $varID = @$this->GetIDForIdent("actual_heating_phase");
            if ($varID === false) {
                $id = $this->RegisterVariableInteger(
                    "actual_heating_phase",
                    $this->Translate("Heating phase"),
                    [   
                        'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
                        'ICON'               => 'calendar-range',
                        'COLOR'              => -1,
                        'PREFIX'             => '',
                        'SUFFIX'             => '',
                        'USAGE_TYPE'         => 0,
                        'PERCENTAGE'         => false,
                        'MIN'                => 0,
                        'MAX'                => 3,
                        'THOUSANDS_SEPARATOR' => '',
                        'DIGITS'             => 0,
                        'DECIMAL_SEPARATOR'  => '',
                        'INTERVALS_ACTIVE'   => true,
                        'INTERVALS'          => json_encode([
                            [
                                'IntervalMinValue' => 0,
                                'IntervalMaxValue' => 0,
                                'ConstantActive'   => true,
                                'ConstantValue'    => 'Heizen',
                                'ConversionFactor' => 1,
                                'PrefixActive'     => false,
                                'PrefixValue'      => '',
                                'SuffixActive'     => false,
                                'SuffixValue'      => '',
                                'DigitsActive'     => false,
                                'DigitsValue'      => 0,
                                'IconActive'       => false,
                                'IconValue'        => '',
                                'ColorActive'      => true,
                                'ColorValue'       => 0xFF0000
                            ],
                            [
                                'IntervalMinValue' => 1,
                                'IntervalMaxValue' => 1,
                                'ConstantActive'   => true,
                                'ConstantValue'    => 'Absenken',
                                'ConversionFactor' => 1,
                                'PrefixActive'     => false,
                                'PrefixValue'      => '',
                                'SuffixActive'     => false,
                                'SuffixValue'      => '',
                                'DigitsActive'     => false,
                                'DigitsValue'      => 0,
                                'IconActive'       => false,
                                'IconValue'        => '',
                                'ColorActive'      => true,
                                'ColorValue'       => 0xFF7F00
                            ],
                            [
                                'IntervalMinValue' => 2,
                                'IntervalMaxValue' => 2,
                                'ConstantActive'   => true,
                                'ConstantValue'    => 'Frostschutz',
                                'ConversionFactor' => 1,
                                'PrefixActive'     => false,
                                'PrefixValue'      => '',
                                'SuffixActive'     => false,
                                'SuffixValue'      => '',
                                'DigitsActive'     => false,
                                'DigitsValue'      => 0,
                                'IconActive'       => false,
                                'IconValue'        => '',
                                'ColorActive'      => true,
                                'ColorValue'       => 0x0000FF
                            ],
                            [
                                'IntervalMinValue' => 3,
                                'IntervalMaxValue' => 3,
                                'ConstantActive'   => true,
                                'ConstantValue'    => 'Aus',
                                'ConversionFactor' => 1,
                                'PrefixActive'     => false,
                                'PrefixValue'      => '',
                                'SuffixActive'     => false,
                                'SuffixValue'      => '',
                                'DigitsActive'     => false,
                                'DigitsValue'      => 0,
                                'IconActive'       => false,
                                'IconValue'        => '',
                                'ColorActive'      => false,
                                'ColorValue'       => -1
                            ],
                        ])
                    ],
                    5
                );
                IPS_SetIcon($id, "calendar-range");
                $this->LogMessage("Raumregelung: Variable actual_heating_phase angelegt.", KL_MESSAGE);
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
                    [
                        'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                        'ICON'         => 'sensor',
                        'OPTIONS'      => json_encode([
                            [
                                'Value'        => false,
                                'Caption'      => 'OK',
                                'IconActive'   => true,
                                'IconValue'    => 'sensor',
                                'ColorActive'  => true,
                                'ColorValue'   => 0x00AA00
                            ],
                            [
                                'Value'        => true,
                                'Caption'      => 'Offen',
                                'IconActive'   => true,
                                'IconValue'    => 'sensor-on',
                                'ColorActive'  => true,
                                'ColorValue'   => 0xFF0000
                            ]
                        ]),
                    ],
                    6
                );
                IPS_SetIcon($id, "sensor");
                $this->LogMessage("Raumregelung: Variable windowdoor_status angelegt.", KL_MESSAGE);
            }
        } else {
            $id = @$this->GetIDForIdent("windowdoor_status");
            if ($id !== false) {
                IPS_DeleteVariable($id);
            }
        }

        // 4.3 Anzeige der externen Heizsteuerung (Heizstopp / Heizstart), falls aktiviert
        if ($this->ReadPropertyBoolean("HeatingBlock_Status")) {
            if (@$this->GetIDForIdent("heatingblock_status") === false) {
                $id = $this->RegisterVariableInteger(
                    "heatingblock_status",
                    $this->Translate("External heating control"),
                    [
                        'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
                        'ICON'               => 'lock',
                        'COLOR'              => -1,
                        'PREFIX'             => '',
                        'SUFFIX'             => '',
                        'USAGE_TYPE'         => 0,
                        'PERCENTAGE'         => false,
                        'MIN'                => 0,
                        'MAX'                => 2,
                        'THOUSANDS_SEPARATOR' => '',
                        'DIGITS'             => 0,
                        'DECIMAL_SEPARATOR'  => '',
                        'INTERVALS_ACTIVE'   => true,
                        'INTERVALS'          => json_encode([
                            [
                                'IntervalMinValue' => 0,
                                'IntervalMaxValue' => 0,
                                'ConstantActive'   => true,
                                'ConstantValue'    => 'OK',
                                'ConversionFactor' => 1,
                                'PrefixActive'     => false,
                                'PrefixValue'      => '',
                                'SuffixActive'     => false,
                                'SuffixValue'      => '',
                                'DigitsActive'     => false,
                                'DigitsValue'      => 0,
                                'IconActive'       => true,
                                'IconValue'        => 'lock',
                                'ColorActive'      => true,
                                'ColorValue'       => 0x00AA00
                            ],
                            [
                                'IntervalMinValue' => 1,
                                'IntervalMaxValue' => 1,
                                'ConstantActive'   => true,
                                'ConstantValue'    => 'Heizstopp',
                                'ConversionFactor' => 1,
                                'PrefixActive'     => false,
                                'PrefixValue'      => '',
                                'SuffixActive'     => false,
                                'SuffixValue'      => '',
                                'DigitsActive'     => false,
                                'DigitsValue'      => 0,
                                'IconActive'       => true,
                                'IconValue'        => 'lock',
                                'ColorActive'      => true,
                                'ColorValue'       => 0xFF0000
                            ],
                            [
                                'IntervalMinValue' => 2,
                                'IntervalMaxValue' => 2,
                                'ConstantActive'   => true,
                                'ConstantValue'    => 'Heizstart',
                                'ConversionFactor' => 1,
                                'PrefixActive'     => false,
                                'PrefixValue'      => '',
                                'SuffixActive'     => false,
                                'SuffixValue'      => '',
                                'DigitsActive'     => false,
                                'DigitsValue'      => 0,
                                'IconActive'       => true,
                                'IconValue'        => 'lock',
                                'ColorActive'      => true,
                                'ColorValue'       => 0xFF0000
                            ],
                        ])
                    ],
                    8
                );
                IPS_SetIcon($id, "lock");
                $this->LogMessage("Raumregelung: Variable heatingblock_status angelegt (Integer, 3 Zustände).", KL_MESSAGE);
            }

            $this->updateHeatingBlockStatusVar();
        } else {
            $id = @$this->GetIDForIdent("heatingblock_status");
            if ($id !== false) {
                IPS_DeleteVariable($id);
            }
        }

        // Aktor-Variable (Solltemperatur) auf VM_UPDATE überwachen
        $controlType = (int)$this->ReadPropertyInteger('HeatingControlType');
        if ($controlType === 0) {
            $actorID = $this->ReadPropertyInteger("ID_Aktor");
            if ($actorID > 0 && IPS_VariableExists($actorID)) {
                $this->RegisterMessage($actorID, VM_UPDATE);
            }
        }

        # 5. Auslesen und registrieren von Änderungen an der Tür- und Fensterauswahl
        $json    = $this->ReadPropertyString('window_sensor');
        $entries = json_decode($json, true);
        foreach ($entries as $row) {
            $instanceID = $row['InstanceID'];
            if (IPS_VariableExists($instanceID)) {
                $this->RegisterMessage($instanceID, VM_UPDATE);
            }
        }

        // 6.0 Initialen Fenster-/Türstatus setzen (Anzeige soll immer stimmen) ===
        if ($this->ReadPropertyBoolean("Windowdoor_Status")) {
            $entries  = json_decode($this->ReadPropertyString('window_sensor'), true);
            $sensorIDs = is_array($entries) ? array_column($entries, 'InstanceID') : [];

            $anyOpen = false;
            foreach ($sensorIDs as $sid) {
                if (IPS_VariableExists($sid) && GetValue($sid)) {
                    $anyOpen = true;
                    break;
                }
            }

            $wdVarID = @$this->GetIDForIdent("windowdoor_status");
            if ($wdVarID !== false) {
                $this->SetValue("windowdoor_status", $anyOpen);
                $this->LogMessage("Raumregelung: Initialer Fensterstatus: " . ($anyOpen ? "offen" : "geschlossen"), KL_MESSAGE);
            }
        }
    }

    public function ApplyTemperatureProfile()
    {
        $targetID = $this->ReadPropertyInteger("Is_Temperature");
        if (!($targetID > 0 && IPS_VariableExists($targetID))) {
            echo $this->Translate("No valid temperature variable selected.");
            return;
        }

        $presentation = @IPS_GetVariablePresentation($targetID);
        if (is_array($presentation) && isset($presentation['SUFFIX']) && str_contains($presentation['SUFFIX'], '°C')) {
            echo $this->Translate("The variable already has a temperature presentation. No changes were made.");
            return;
        }

        IPS_SetVariableCustomPresentation($targetID, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'TEMPLATE'     => VARIABLE_TEMPLATE_VALUE_PRESENTATION_ROOM_TEMPERATURE
        ]);
        $this->LogMessage("Raumregelung: Darstellung (Wertanzeige: Raumtemperatur) auf Variable #$targetID gesetzt.", KL_MESSAGE);
        echo $this->Translate("Presentation (Value display: Room temperature) successfully applied.");
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $controlType = (int)$this->ReadPropertyInteger('HeatingControlType');
        $heatingVarID  = $this->ReadAttributeInteger("HeatingStatusVarID");
        $vacationVarID = $this->ReadAttributeInteger("VacationStatusVarID");
        $planID        = $this->ReadAttributeInteger("HeatingPlanID");
        $actorID       = ($controlType === 0) ? $this->ReadPropertyInteger("ID_Aktor") : 0;
        $frostschutz   = $this->ReadPropertyFloat("FrostProtection");

        $heatingBlockVarID = (int)$this->ReadPropertyInteger("HeatingBlock_VarID");
        if ($Message === VM_UPDATE && $heatingBlockVarID > 0 && $SenderID === $heatingBlockVarID) {
            $this->applyHeatingBlockState();
            return;
        }

        $forceHeatingVarID = (int)$this->ReadPropertyInteger("ForceHeating_VarID");
        if ($Message === VM_UPDATE && $forceHeatingVarID > 0 && $SenderID === $forceHeatingVarID) {
            $this->applyForceHeatingState();
            return;
        }

        $isTempVarID = $this->ReadPropertyInteger("Is_Temperature");
        if ($Message === VM_UPDATE && $SenderID === $isTempVarID) {
            $this->updateTwoPointSwitch();
            return;
        }

        if ($Message === VM_UPDATE && ($SenderID === $heatingVarID || $SenderID === $vacationVarID)) {
            $heatingActive  = GetValue($heatingVarID);
            $vacationActive = GetValue($vacationVarID);

            // 2.1 Heizungs-Status geändert
            if ($SenderID === $heatingVarID) {
                // A: Heizung aus (kein Urlaub) → Backup + Frostschutz
                if ($heatingActive === false && $vacationActive === false) {
                    if ($actorID > 0 && IPS_VariableExists($actorID)) {
                        $heatVarID = $this->GetIDForIdent("set_heating_temperature");
                        $lowVarID  = $this->GetIDForIdent("set_lowering_temperature");

                        $currentHeat = ($heatVarID > 0 ? (float)GetValue($heatVarID) : 0.0);
                        $currentLow  = ($lowVarID  > 0 ? (float)GetValue($lowVarID)  : 0.0);

                        // Backups nur sichern, wenn sie sich vom Zielwert unterscheiden
                        if ($currentHeat !== (float)$frostschutz) {
                            $this->WriteAttributeFloat("BackupActorSollTemp", $currentHeat);
                            $this->LogMessage("Raumregelung: Backup HEAT (Heizung aus): {$currentHeat}", KL_MESSAGE);
                        } else {
                            $this->LogMessage("Raumregelung: Kein HEAT-Backup (bereits Frostschutz {$currentHeat})", KL_MESSAGE);
                        }

                        if ($currentLow !== (float)$frostschutz) {
                            $this->WriteAttributeFloat("BackupLoweringTemp", $currentLow);
                            $this->LogMessage("Raumregelung: Backup LOW (Heizung aus): {$currentLow}", KL_MESSAGE);
                        } else {
                            $this->LogMessage("Raumregelung: Kein LOW-Backup (bereits Frostschutz {$currentLow})", KL_MESSAGE);
                        }

                        // Aktor und Slider setzen (beide Slider exakt auf FrostProtection)
                        $this->writeAnalogActorTargetConsideringHeatingBlock($actorID, (float)$frostschutz);
                        if ($heatVarID) {
                            $this->SetValue("set_heating_temperature", (float)$frostschutz);
                        }
                        if ($lowVarID) {
                            $this->SetValue("set_lowering_temperature", (float)$frostschutz);
                        }

                        IPS_SetDisabled($actorID, true);
                    }
                }

                // B: Heizung an (kein Urlaub) → Restore
                elseif ($heatingActive === true && $vacationActive === false) {
                    if ($actorID > 0 && IPS_VariableExists($actorID)) {
                        // Wenn Übersteuerung aktiv ist, Aktor direkt auf Heiz-Soll setzen und Backup/Restore überspringen
                        if ($this->isOverrideActive()) {
                            $heatVarID = $this->GetIDForIdent("set_heating_temperature");
                            if ($heatVarID && IPS_VariableExists($heatVarID)) {
                                $target = (float)GetValue($heatVarID);
                                $this->writeAnalogActorTargetConsideringHeatingBlock($actorID, (float)$target);
                                $this->LogMessage("Raumregelung: Heizung an bei aktivem Override: Aktor auf {$target}°C gesetzt.", KL_MESSAGE);
                                IPS_SetDisabled($actorID, false);
                                return; // Restliche Restore-Logik überspringen
                            }
                        }
                        $backupHeat = $this->ReadAttributeFloat("BackupActorSollTemp");
                        $backupLow  = $this->ReadAttributeFloat("BackupLoweringTemp");

                        if (!is_null($backupHeat)) {
                            // Aktuelle Planphase ermitteln
                            $action = $this->getCurrentPlanActionId(); // -1, 0, 1

                            // Slider zuerst lokal restaurieren
                            $this->SetValue("set_heating_temperature", $backupHeat);

                            $lowVarID = $this->GetIDForIdent("set_lowering_temperature");
                            if ($lowVarID && IPS_VariableExists($lowVarID) && !is_null($backupLow)) {
                                // In Sliderbereich klemmen und (sicherheitshalber) nicht über Heizwert lassen
                                $restoreLow = max(10.0, min(25.0, (float)$backupLow));
                                if ($restoreLow > $backupHeat) {
                                    $restoreLow = $backupHeat;
                                }
                                $this->SetValue("set_lowering_temperature", $restoreLow);
                                $this->LogMessage("Raumregelung: LOW-Backup wiederhergestellt: {$restoreLow}", KL_MESSAGE);
                            }

                            // ECHO-Schutz aktivieren und den Aktor passend zur Phase setzen
                            // Heizen (0/-1) -> Heiz-Backup; Absenken (1) -> Low-Backup
                            $this->markActorWriteFromModule();
                            $targetForActor = $backupHeat;
                            if ($action === 1 && isset($restoreLow)) {
                                $targetForActor = $restoreLow;
                            }
                            $this->writeAnalogActorTargetConsideringHeatingBlock($actorID, (float)$targetForActor);

                            $this->LogMessage("Raumregelung: Aktor {$actorID} auf Restore-Ziel {$targetForActor} gesetzt (Phase {$action}).", KL_MESSAGE);
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
                        $heatVarID = $this->GetIDForIdent("set_heating_temperature");
                        $lowVarID  = $this->GetIDForIdent("set_lowering_temperature");

                        $currentHeat = ($heatVarID > 0 ? (float)GetValue($heatVarID) : 0.0);
                        $currentLow  = ($lowVarID  > 0 ? (float)GetValue($lowVarID)  : 0.0);

                        if ($currentHeat !== (float)$frostschutz) {
                            $this->WriteAttributeFloat("BackupActorSollTemp", $currentHeat);
                            $this->LogMessage("Raumregelung: Backup HEAT (Urlaub an): {$currentHeat}", KL_MESSAGE);
                        } else {
                            $this->LogMessage("Raumregelung: Kein HEAT-Backup (bereits Frostschutz {$currentHeat})", KL_MESSAGE);
                        }

                        if ($currentLow !== (float)$frostschutz) {
                            $this->WriteAttributeFloat("BackupLoweringTemp", $currentLow);
                            $this->LogMessage("Raumregelung: Backup LOW (Urlaub an): {$currentLow}", KL_MESSAGE);
                        } else {
                            $this->LogMessage("Raumregelung: Kein LOW-Backup (bereits Frostschutz {$currentLow})", KL_MESSAGE);
                        }

                        $this->writeAnalogActorTargetConsideringHeatingBlock($actorID, (float)$frostschutz);
                        // beide Slider exakt auf FrostProtection setzen
                        if ($heatVarID) {
                            $this->SetValue("set_heating_temperature", (float)$frostschutz);
                        }
                        if ($lowVarID) {
                            $this->SetValue("set_lowering_temperature", (float)$frostschutz);
                        }

                        IPS_SetDisabled($actorID, true);
                    }
                }

                // D: Urlaub aus (Heizung an) → Restore
                elseif ($vacationActive === false && $heatingActive === true) {
                    if ($actorID > 0 && IPS_VariableExists($actorID)) {
                        $backupHeat = $this->ReadAttributeFloat("BackupActorSollTemp");
                        $backupLow  = $this->ReadAttributeFloat("BackupLoweringTemp");

                        if (!is_null($backupHeat)) {
                            // Aktuelle Planphase ermitteln
                            $action = $this->getCurrentPlanActionId(); // -1, 0, 1

                            // Slider zuerst lokal restaurieren
                            $this->SetValue("set_heating_temperature", $backupHeat);

                            $lowVarID = $this->GetIDForIdent("set_lowering_temperature");
                            if ($lowVarID && IPS_VariableExists($lowVarID) && !is_null($backupLow)) {
                                $restoreLow = max(10.0, min(25.0, (float)$backupLow));
                                if ($restoreLow > $backupHeat) {
                                    $restoreLow = $backupHeat;
                                }
                                $this->SetValue("set_lowering_temperature", $restoreLow);
                                $this->LogMessage("Raumregelung: LOW-Backup wiederhergestellt: {$restoreLow}", KL_MESSAGE);
                            }

                            // ECHO-Schutz aktivieren und den Aktor passend zur Phase setzen
                            $this->markActorWriteFromModule();
                            $targetForActor = $backupHeat;
                            if ($action === 1 && isset($restoreLow)) {
                                $targetForActor = $restoreLow;
                            }
                            $this->writeAnalogActorTargetConsideringHeatingBlock($actorID, (float)$targetForActor);

                            $this->LogMessage("Raumregelung: Aktor {$actorID} auf Restore-Ziel {$targetForActor} gesetzt (Phase {$action}).", KL_MESSAGE);
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

            // Override-Variable deaktivieren/aktivieren und ggf. zurücksetzen
            $overrideVarID = @$this->GetIDForIdent("manual_override");
            if ($overrideVarID !== false && IPS_VariableExists($overrideVarID)) {
                IPS_SetDisabled($overrideVarID, $disable);
                if ($disable && (bool)GetValue($overrideVarID)) {
                    $this->SetValue("manual_override", false);
                    $this->WriteAttributeString("OverrideDate", "");
                    $this->LogMessage("Raumregelung: Override automatisch deaktiviert (Heizung aus oder Urlaub an).", KL_MESSAGE);
                }
            }

            // === NEU: Aktor synchron zu den Slidern sperren/freigeben ===
            if ($actorID > 0 && IPS_VariableExists($actorID)) {
                IPS_SetDisabled($actorID, $disable);
            }

            // 3. Wochenplan an/aus
            if ($planID > 0 && IPS_EventExists($planID)) {
                if ($vacationActive || !$heatingActive) {
                    IPS_SetEventActive($planID, false);
                    $this->LogMessage("Raumregelung: Wochenplan (ID {$planID}) deaktiviert.", KL_MESSAGE);
                } else {
                    IPS_SetEventActive($planID, true);
                    $this->LogMessage("Raumregelung: Wochenplan (ID {$planID}) aktiviert.", KL_MESSAGE);
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
        if ($controlType === 0 && $Message === VM_UPDATE && $SenderID === $actorID) {
            // Eigene Echos kurzzeitig ignorieren
            if ($this->isRecentActorEchoFromModule(2)) {
                $this->resetActorWriteMark(); // einmalig löschen
                return;
            }

            $heatingActive  = ($heatingVarID  > 0) ? GetValue($heatingVarID)  : true;
            $vacationActive = ($vacationVarID > 0) ? GetValue($vacationVarID) : false;

            // Bei Heizung AUS oder Urlaub AN oder externer Heizsperre: jede externe Aktor-Änderung sofort auf Frostschutz zurücksetzen
            if ($vacationActive || !$heatingActive || $this->isHeatingBlocked()) {
                $this->writeAnalogActorTargetConsideringHeatingBlock($actorID, (float)$frostschutz);

                $heatVarID = $this->GetIDForIdent("set_heating_temperature");
                if ($heatVarID && IPS_VariableExists($heatVarID)) {
                    $this->SetValue("set_heating_temperature", $frostschutz);
                }

                $this->LogMessage("Raumregelung: Externe Aktor-Änderung verworfen (Heizung AUS/Urlaub AN). Zurückgesetzt auf Frostschutz {$frostschutz}°C.", KL_MESSAGE);
                return; // nichts weiter spiegeln 
            }

            $actorValue = (float)GetValue($actorID);

            $action   = $this->getCurrentPlanActionId(); // -1, 0, 1
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

                case 1: // Absenken -> Lowering ist absolut = Aktorwert spiegeln
                    if ($lowVarID && IPS_VariableExists($lowVarID)) {
                        // optional Clamp an deinen Slider (MIN=10, MAX=25)
                        $newLowering = max(10.0, min(25.0, $actorValue));
                        if (abs($newLowering - (float)GetValue($lowVarID)) > 0.05) {
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

        // Heizung/Urlaub nur für Aktor-/Timer-Entscheidung
        $heatingVarID  = $this->ReadAttributeInteger("HeatingStatusVarID");
        $vacationVarID = $this->ReadAttributeInteger("VacationStatusVarID");
        $heatingActive  = ($heatingVarID  > 0) ? GetValue($heatingVarID)  : true;
        $vacationActive = ($vacationVarID > 0) ? GetValue($vacationVarID) : false;

        // prüfen, ob mind. ein Fenster offen ist
        $anyWindowOpen = false;
        foreach ($sensorIDs as $sid) {
            if (IPS_VariableExists($sid) && GetValue($sid)) {
                $anyWindowOpen = true;
                break;
            }
        }

        // Status IMMER aktualisieren – unabhängig von Heizung/Urlaub
        $wdVarID = @$this->GetIDForIdent("windowdoor_status");
        if ($wdVarID !== false) {
            $this->SetValue("windowdoor_status", $anyWindowOpen);
        }

        if ($anyWindowOpen) {
            $this->LogMessage("Raumregelung: Mindestens ein Fenster ist geöffnet.", KL_MESSAGE);
            // Absenk-Timer nur, wenn Heizung AN & Urlaub AUS
            if ($heatingActive && !$vacationActive) {
                $delayMs = intval($this->ReadPropertyFloat('windowdoor_reporting_delay') * 1000);
                $this->SetTimerInterval("WindowOpenTimer", $delayMs);
                $this->LogMessage("Raumregelung: Fenster geöffnet: Timer gestartet.", KL_MESSAGE);
            } else {
                // Sicherheitshalber keinen Timer laufen lassen
                $this->SetTimerInterval("WindowOpenTimer", 0);
            }
        } else {
            $this->LogMessage("Raumregelung: Alle Fenster geschlossen.", KL_MESSAGE);
            // Plan-Ziel & Aktor nur bei Heizung AN & Urlaub AUS wiederherstellen
            if ($controlType === 0 && $heatingActive && !$vacationActive) {
                $target  = $this->getTargetSetpointForCurrentPhase();
                if ($actorID > 0 && IPS_VariableExists($actorID)) {
                    $this->writeAnalogActorTargetConsideringHeatingBlock((int)$actorID, (float)$target);
                    $this->LogMessage("Raumregelung: Plan-Ziel {$target}°C wiederhergestellt.", KL_MESSAGE);
                }
            }
            // Timer immer stoppen
            $this->SetTimerInterval("WindowOpenTimer", 0);
        }

        // 2-Punkt-Regelung neu bewerten, wenn Fensterzustand das Target beeinflussen kann
        if ($controlType === 1) {
            $this->updateTwoPointSwitch();
        }
    }

    private function updateTwoPointSwitch(): void
    {
        $controlType = (int)$this->ReadPropertyInteger('HeatingControlType');
        if ($controlType !== 1) {
            return;
        }

        $isTempVarID = (int)$this->ReadPropertyInteger("Is_Temperature");
        if ($isTempVarID <= 0 || !IPS_VariableExists($isTempVarID)) {
            return;
        }

        $switchActorID = (int)$this->ReadPropertyInteger("ID_SwitchAktor");
        if ($switchActorID <= 0 || !IPS_VariableExists($switchActorID)) {
            return;
        }

        $heatingVarID  = (int)$this->ReadAttributeInteger("HeatingStatusVarID");
        $vacationVarID = (int)$this->ReadAttributeInteger("VacationStatusVarID");

        $heatingActive  = ($heatingVarID  > 0 && IPS_VariableExists($heatingVarID)) ? (bool)GetValue($heatingVarID)  : true;
        $vacationActive = ($vacationVarID > 0 && IPS_VariableExists($vacationVarID)) ? (bool)GetValue($vacationVarID) : false;

        $ist = (float)GetValue($isTempVarID);

        if (!$heatingActive || $vacationActive) {
            $target = (float)$this->ReadPropertyFloat("FrostProtection");
        } else {
            $target = $this->getTargetSetpointForCurrentPhase();

            $entries   = json_decode($this->ReadPropertyString('window_sensor'), true);
            $sensorIDs = is_array($entries) ? array_column($entries, 'InstanceID') : [];
            foreach ($sensorIDs as $sid) {
                if (IPS_VariableExists($sid) && (bool)GetValue($sid)) {
                    $target = (float)$this->ReadPropertyFloat('windowdoor_temperature');
                    break;
                }
            }
        }

        $current = (bool)GetValue($switchActorID);
        $invert  = (bool)$this->ReadPropertyBoolean("Invert_SwitchAktor");

        $h = (float)$this->ReadPropertyFloat("SwitchHysteresis");
        if ($h < 0.0) {
            $h = 0.0;
        }

        $logicalCurrent = $invert ? !$current : $current;
        $logicalDesired = $logicalCurrent;

        if ($h > 0.0) {
            if ($ist <= ($target - $h)) {
                $logicalDesired = true;
            } elseif ($ist >= ($target + $h)) {
                $logicalDesired = false;
            }
        } else {
            $logicalDesired = ($ist < $target);
        }

        if ($this->isHeatingBlocked()) {
            $logicalDesired = false;
        }

        $desired = $invert ? !$logicalDesired : $logicalDesired;
        if ($current !== $desired) {
            RequestAction($switchActorID, $desired);
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
        // Auto-Reset der Übersteuerung bei Tageswechsel, ohne Timer
        $this->ensureOverrideValidity();

        // Wenn Übersteuerung aktiv: Phase erzwingen = Heizen (0)
        if ($this->isOverrideActive() || $this->isForceHeatingActive()) {
            return 0;
        }
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
            return $lowering;  // Absenkphase
        }
        return $heating;       // Heizen oder konservativ
    }

    // Prüft, ob wir uns (heute) ab dem letzten Absenk-Zeitpunkt befinden
    private function isInLastLoweringOfToday(int $planID): bool
    {
        if ($planID <= 0 || !IPS_EventExists($planID)) {
            return false;
        }
        $event = IPS_GetEvent($planID);
        if (!isset($event['ScheduleGroups']) || !is_array($event['ScheduleGroups'])) {
            return false;
        }

        // Tagesmaske ermitteln: Mo=1<<0 .. So=1<<6
        $n = (int)date('N'); // 1..7 (Mo..So)
        $todayMask = (1 << ($n - 1));

        $lastLoweringSec = -1;
        foreach ($event['ScheduleGroups'] as $g) {
            if (!isset($g['Days']) || (($g['Days'] & $todayMask) === 0)) {
                continue;
            }
            if (!isset($g['Points']) || !is_array($g['Points'])) {
                continue;
            }
            foreach ($g['Points'] as $p) {
                if (!isset($p['ActionID']) || $p['ActionID'] !== 1) {
                    continue;
                }
                $sec = ($p['Start']['Hour'] ?? 0) * 3600 + ($p['Start']['Minute'] ?? 0) * 60 + ($p['Start']['Second'] ?? 0);
                if ($sec > $lastLoweringSec) {
                    $lastLoweringSec = $sec;
                }
            }
        }

        if ($lastLoweringSec < 0) {
            return false; // heute keine Absenkpunkte
        }
        $nowSec = (int)date('G') * 3600 + (int)date('i') * 60 + (int)date('s');
        return ($nowSec >= $lastLoweringSec);
    }

    // Echo-Schutz markieren, wenn das Modul den Aktor setzt
    private function markActorWriteFromModule(): void
    {
        $this->WriteAttributeString("LastActorWriteSource", "module");
        $this->WriteAttributeInteger("LastActorWriteTime", time());
    }

    // Echo-Schutz: VM_UPDATE kurz nach unserem Write ignorieren
    private function isRecentActorEchoFromModule(int $graceSec = 2): bool
    {
        $src  = $this->ReadAttributeString("LastActorWriteSource");
        $when = $this->ReadAttributeInteger("LastActorWriteTime");
        return ($src === "module" && (time() - $when) <= $graceSec);
    }

    // Echo-Schutz zurücksetzen
    private function resetActorWriteMark(): void
    {
        $this->WriteAttributeString("LastActorWriteSource", "");
    }

    private function isHeatingBlocked(): bool
    {
        $varID = (int)$this->ReadPropertyInteger("HeatingBlock_VarID");
        return ($varID > 0 && IPS_VariableExists($varID) && (bool)GetValue($varID));
    }

    private function isForceHeatingActive(): bool
    {
        // Heizstopp hat Vorrang: wenn Heizsperre aktiv, kein erzwungenes Heizen
        if ($this->isHeatingBlocked()) {
            return false;
        }
        $varID = (int)$this->ReadPropertyInteger("ForceHeating_VarID");
        return ($varID > 0 && IPS_VariableExists($varID) && (bool)GetValue($varID));
    }

    private function applyForceHeatingState(): void
    {
        $controlType = (int)$this->ReadPropertyInteger('HeatingControlType');

        $this->updateHeatingBlockStatusVar();

        // 2-Punkt: Zustandsmaschine über updateTwoPointSwitch (Phase=Heizen wird in getCurrentPlanActionId berücksichtigt)
        if ($controlType === 1) {
            $this->updateTwoPointSwitch();
            $this->updateHeatingPhaseState($this->ReadAttributeInteger("HeatingPlanID"));
            return;
        }

        // Analog
        if ($controlType !== 0) {
            $this->updateHeatingPhaseState($this->ReadAttributeInteger("HeatingPlanID"));
            return;
        }

        $actorID = (int)$this->ReadPropertyInteger("ID_Aktor");
        if (!($actorID > 0 && IPS_VariableExists($actorID))) {
            $this->updateHeatingPhaseState($this->ReadAttributeInteger("HeatingPlanID"));
            return;
        }

        if ($this->isForceHeatingActive()) {
            // Sofort auf Heiz-Soll setzen
            $heatVarID = $this->GetIDForIdent("set_heating_temperature");
            if ($heatVarID && IPS_VariableExists($heatVarID)) {
                $target = (float)GetValue($heatVarID);
                $this->writeAnalogActorTargetConsideringHeatingBlock((int)$actorID, (float)$target);
            }
            // Marker setzen, damit Anzeige stabil "Heizen" bleibt
            $this->WriteAttributeInteger("LastPlanAction", 0);
            $this->updateHeatingPhaseState($this->ReadAttributeInteger("HeatingPlanID"));
            return;
        }

        // Wenn externes Erzwingen beendet: wieder nach Plan fahren
        $planID = (int)$this->ReadAttributeInteger("HeatingPlanID");
        if ($planID > 0 && IPS_EventExists($planID)) {
            $this->WriteAttributeInteger("LastPlanAction", -1);
            $this->executeCurrentPlanAction($planID);
        }
        $this->updateHeatingPhaseState($this->ReadAttributeInteger("HeatingPlanID"));
    }

    private function applyHeatingBlockState(): void
    {
        $controlType = (int)$this->ReadPropertyInteger('HeatingControlType');

        $this->updateHeatingBlockStatusVar();

        // 2-Punkt: Zustandsmaschine über updateTwoPointSwitch
        if ($controlType === 1) {
            $this->updateTwoPointSwitch();
            $this->updateHeatingPhaseState($this->ReadAttributeInteger("HeatingPlanID"));
            return;
        }

        // Analog: Frostschutz setzen, wenn block aktiv; sonst aktuellen Zielwert wieder anfahren
        if ($controlType !== 0) {
            $this->updateHeatingPhaseState($this->ReadAttributeInteger("HeatingPlanID"));
            return;
        }

        $actorID = (int)$this->ReadPropertyInteger("ID_Aktor");
        if (!($actorID > 0 && IPS_VariableExists($actorID))) {
            $this->updateHeatingPhaseState($this->ReadAttributeInteger("HeatingPlanID"));
            return;
        }

        if ($this->isHeatingBlocked()) {
            $this->writeAnalogActorTargetConsideringHeatingBlock($actorID, (float)$this->ReadPropertyFloat("FrostProtection"));
            $this->updateHeatingPhaseState($this->ReadAttributeInteger("HeatingPlanID"));
            return;
        }

        $heatingVarID  = (int)$this->ReadAttributeInteger("HeatingStatusVarID");
        $vacationVarID = (int)$this->ReadAttributeInteger("VacationStatusVarID");

        $heatingActive  = ($heatingVarID  > 0 && IPS_VariableExists($heatingVarID)) ? (bool)GetValue($heatingVarID)  : true;
        $vacationActive = ($vacationVarID > 0 && IPS_VariableExists($vacationVarID)) ? (bool)GetValue($vacationVarID) : false;

        if (!$heatingActive || $vacationActive) {
            $target = (float)$this->ReadPropertyFloat("FrostProtection");
        } else {
            $target = (float)$this->getTargetSetpointForCurrentPhase();
        }
        $this->writeAnalogActorTargetConsideringHeatingBlock($actorID, (float)$target);
        $this->updateHeatingPhaseState($this->ReadAttributeInteger("HeatingPlanID"));
    }

    private function updateHeatingBlockStatusVar(): void
    {
        $id = @$this->GetIDForIdent("heatingblock_status");
        if ($id === false || !IPS_VariableExists($id)) {
            return;
        }
        if ($this->isHeatingBlocked()) {
            $state = 1; // Heizstopp
        } elseif ($this->isForceHeatingActive()) {
            $state = 2; // Heizstart
        } else {
            $state = 0; // Inaktiv
        }

        $this->SetValue("heatingblock_status", $state);
    }

    private function writeAnalogActorTargetConsideringHeatingBlock(int $actorID, float $target): void
    {
        if (!($actorID > 0 && IPS_VariableExists($actorID))) {
            return;
        }

        if ($this->isHeatingBlocked()) {
            $target = (float)$this->ReadPropertyFloat("FrostProtection");
        }

        $this->markActorWriteFromModule();
        RequestAction($actorID, $target);
    }

    // Führt die aktuelle Plan-Aktion aus, setzt Marker und aktualisiert die Anzeige sofort
    private function executeCurrentPlanAction(int $planID)
    {
        if ($planID <= 0 || !IPS_EventExists($planID)) {
            return;
        }

        // Auto-Reset prüfen und ggf. Übersteuerung berücksichtigen
        $this->ensureOverrideValidity();
        $manualOverrideActive = $this->isOverrideActive();
        $forceHeatingActive   = $this->isForceHeatingActive();
        if ($manualOverrideActive || $forceHeatingActive) {
            // Sonderfall: Option aktiv und wir befinden uns im letzten Absenkbereich → *nur* manuellen Override automatisch deaktivieren
            if ($manualOverrideActive) {
                $autoDisable = $this->ReadPropertyBoolean("Auto_Disable_Override_Last_Lowering");
                if ($autoDisable) {
                    $actionID = $this->getActivePlanActionId($planID); // 0/1 oder false
                    if ($actionID === 1 && $this->isInLastLoweringOfToday($planID)) {
                        // Aktivierung verhindern und Schalter zurücksetzen
                        $this->removeOverride();
                        return; // removeOverride führt Plan/Anzeige bereits aus
                    }
                }
            }

            // In (restlicher) Übersteuerung / externem Erzwingen wird kein Plan ausgeführt
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
            $a      = $event['ScheduleActions'][$actionID];
            $script = $a['ScriptText'] ?: ($a['ActionParameters']['SCRIPT'] ?? "");
            if ($script !== "") {
                // Aktionsskript ausführen (stellt den Aktor)
                IPS_RunScriptText($script);
                $this->LogMessage("Raumregelung: ExecutePlanAction: Aktion {$actionID} ausgeführt.", KL_MESSAGE);

                // Phase SOFORT anzeigen + manuell gesetzte Phase merken
                $this->WriteAttributeInteger("LastPlanAction", (int)$actionID);

                $varID = @$this->GetIDForIdent("actual_heating_phase");
                if ($varID !== false) {
                    $this->SetValue("actual_heating_phase", (int)$actionID); // 0=Heizen, 1=Absenken
                }
            } else {
                $this->LogMessage("Raumregelung: ExecutePlanAction: Kein Script für Aktion {$actionID}.", KL_MESSAGE);
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
            $this->LogMessage("Raumregelung: Alter Wochenplan (ID {$existingPlanID}) gelöscht.", KL_MESSAGE);
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
            "IPS_RequestAction($iid, \"__ApplyAnalogActorTargetConsideringHeatingBlock\", GetValue({$tempVarID1}));"
        );
        IPS_SetEventScheduleAction(
            $heatingPlan,
            1,
            "Absenken",
            0xFF7F00,
            "IPS_RequestAction($iid, \"__ApplyAnalogActorTargetConsideringHeatingBlock\", GetValue({$tempVarID2}));"
        );

        // 5) Gruppen/Points je nach Auswahl
        switch ($selection) {
            case 1:
                // Eine Gruppe für alle Tage
                IPS_SetEventScheduleGroup($heatingPlan, 0, 127);
                IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 0, 0, 0, 0, 0);   // 00:00 -> (Aktion 0)
                IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 1, 6, 0, 0, 1);   // 06:00 -> Heizen
                IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 2, 20, 0, 0, 0);  // 20:00 -> Absenken
                break;

            case 2:
                // Mo–Fr + Sa–So getrennt
                IPS_SetEventScheduleGroup($heatingPlan, 0, 31); // Mo–Fr
                IPS_SetEventScheduleGroup($heatingPlan, 1, 96); // Sa–So

                IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 0, 0, 0, 0, 0);
                IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 1, 6, 0, 0, 1);
                IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 2, 20, 0, 0, 0);

                IPS_SetEventScheduleGroupPoint($heatingPlan, 1, 0, 0, 0, 0, 0);
                IPS_SetEventScheduleGroupPoint($heatingPlan, 1, 1, 8, 0, 0, 1);
                IPS_SetEventScheduleGroupPoint($heatingPlan, 1, 2, 22, 0, 0, 0);
                break;

            case 3:
                // Jede Wochengruppe einzeln
                for ($i = 0; $i < 7; $i++) {
                    IPS_SetEventScheduleGroup($heatingPlan, $i, (1 << $i));
                    IPS_SetEventScheduleGroupPoint($heatingPlan, $i, 0, 0, 0, 0, 0);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, $i, 1, 6, 0, 0, 1);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, $i, 2, 20, 0, 0, 0);
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

        $this->LogMessage("Raumregelung: Neuer Wochenplan angelegt (ID {$heatingPlan}).", KL_MESSAGE);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case "__MarkActorWriteByModule":
                $this->markActorWriteFromModule();
                return;

            case "__ApplyAnalogActorTargetConsideringHeatingBlock":
                $controlType = (int)$this->ReadPropertyInteger('HeatingControlType');
                if ($controlType !== 0) {
                    return;
                }
                $actorID = (int)$this->ReadPropertyInteger("ID_Aktor");
                if (!($actorID > 0 && IPS_VariableExists($actorID))) {
                    return;
                }
                $this->writeAnalogActorTargetConsideringHeatingBlock($actorID, (float)$Value);
                return;

            case "set_heating_temperature":
                $this->SetValue("set_heating_temperature", $Value);

                $controlType = (int)$this->ReadPropertyInteger('HeatingControlType');

                // 2-Punkt-Regelung neu bewerten
                if ($controlType === 1) {
                    $this->updateTwoPointSwitch();
                    break;
                }

                if ($controlType !== 0) {
                    break;
                }

                $actorID = $this->ReadPropertyInteger("ID_Aktor");

                // NEU: Konsistenz – Lowering darf nicht über Heating liegen
                $lowVarID = $this->GetIDForIdent("set_lowering_temperature");
                if ($lowVarID && IPS_VariableExists($lowVarID)) {
                    $low  = (float)GetValue($lowVarID);
                    $heat = (float)$Value;

                    // Clamp sicherheitshalber (falls per Script unsinnige Werte drin stehen)
                    if ($low < 10.0) {
                        $low = 10.0;
                    }
                    if ($low > 25.0) {
                        $low = 25.0;
                    }

                    if ($low > $heat) {
                        $this->SetValue("set_lowering_temperature", $heat);
                    }
                }

                // Nur im Automatikmodus den physikalischen Aktor beschreiben
                if (!($actorID > 0 && IPS_VariableExists($actorID))) {
                    break;
                }

                // Phasenlogik: nur senden, wenn nicht Action 1 aktiv ist
                $action = $this->getCurrentPlanActionId(); // -1, 0, 1
                if ($action === 1) {
                    $this->LogMessage("Raumregelung: Heating-Änderung geblockt (Action 1 aktiv).", KL_MESSAGE);
                    break;
                }

                $this->writeAnalogActorTargetConsideringHeatingBlock((int)$actorID, (float)$Value);
                break;

            case "set_lowering_temperature":
                $actorID = $this->ReadPropertyInteger("ID_Aktor");

                // 1) Clamp auf Sliderbereich 10..25
                $val = max(10.0, min(25.0, (float)$Value));

                // 2) Lowering darf nie über Heating liegen
                $heatVarID = $this->GetIDForIdent("set_heating_temperature");
                $heat      = ($heatVarID && IPS_VariableExists($heatVarID)) ? (float)GetValue($heatVarID) : 25.0;
                if ($val > $heat) {
                    $val = $heat;
                }

                // 3) Speichern
                $this->SetValue("set_lowering_temperature", $val);

                // 2-Punkt-Regelung neu bewerten
                $controlType = (int)$this->ReadPropertyInteger('HeatingControlType');
                if ($controlType === 1) {
                    $this->updateTwoPointSwitch();
                    break;
                }

                // Nur im Automatikmodus den physikalischen Aktor beschreiben
                if ($controlType !== 0) {
                    break;
                }

                // 4) Nur in Absenkphase senden
                $action = $this->getCurrentPlanActionId(); // -1, 0, 1
                if ($action === 0) {
                    $this->LogMessage("Raumregelung: Lowering-Änderung geblockt (Action 0 aktiv).", KL_MESSAGE);
                    break;
                }

                if ($action === 1) {
                    if ($actorID > 0 && IPS_VariableExists($actorID)) {
                        $this->writeAnalogActorTargetConsideringHeatingBlock((int)$actorID, (float)$val); // absolut & validiert
                    }
                    break;
                }

                // action === -1 (konservativ: nicht senden)
                $this->LogMessage("Raumregelung: Lowering geändert, aber keine passende Action aktiv – kein Senden.", KL_MESSAGE);
                break;

            case "WindowOpenTimer":
                $controlType = (int)$this->ReadPropertyInteger('HeatingControlType');
                if ($controlType !== 0) {
                    $this->SetTimerInterval("WindowOpenTimer", 0);
                    return;
                }

                $this->executeWindowOpenLowering();
                break;

            case "manual_override":
                // Ein-/Ausschalten der Übersteuerung
                $enable = (bool)$Value;
                if ($enable) {
                    // Override nur aktivierbar, wenn Heizung aktiv ist
                    $heatingVarID = $this->ReadAttributeInteger("HeatingStatusVarID");
                    $heatingActive = ($heatingVarID > 0) ? (bool)GetValue($heatingVarID) : true;
                    if (!$heatingActive) {
                        $this->SetValue("manual_override", false);
                        $this->LogMessage("Raumregelung: Override-Aktivierung blockiert (Heizung aus).", KL_MESSAGE);
                        break;
                    }

                    // Wenn Option aktiv ist: Aktivierung in der letzten Absenkphase unterbinden
                    $autoDisable = $this->ReadPropertyBoolean("Auto_Disable_Override_Last_Lowering");
                    if ($autoDisable) {
                        $planID = $this->ReadAttributeInteger("HeatingPlanID");
                        if ($planID > 0 && IPS_EventExists($planID)) {
                            $actionID = $this->getActivePlanActionId($planID); // 0/1 oder false
                            if ($actionID === 1 && $this->isInLastLoweringOfToday($planID)) {
                                // Aktivierung verhindern und Schalter zurücksetzen
                                $this->SetValue("manual_override", false);
                                $this->LogMessage("Raumregelung: Override-Aktivierung in der letzten Absenkphase unterbunden (Option aktiv).", KL_MESSAGE);
                                break;
                            }
                        }
                    }
                    $this->applyOverride();
                } else {
                    $this->removeOverride();
                }

                // 2-Punkt-Regelung: sofort neu bewerten (Override erzwingt Phase=Heizen)
                $controlType = (int)$this->ReadPropertyInteger('HeatingControlType');
                if ($controlType === 1) {
                    $this->updateTwoPointSwitch();
                }
                break;
        }
    }

    // === Übersteuerungs-Funktionen ===
    private function isOverrideActive(): bool
    {
        $id = @IPS_GetObjectIDByIdent("manual_override", $this->InstanceID);
        if ($id && IPS_VariableExists($id)) {
            return (bool)GetValue($id);
        }
        return false;
    }

    private function ensureOverrideValidity(): void
    {
        // Wenn aktiv und Datum != heute, dann deaktivieren
        $id = @IPS_GetObjectIDByIdent("manual_override", $this->InstanceID);
        if ($id && IPS_VariableExists($id) && (bool)GetValue($id)) {
            $stored = (string)$this->ReadAttributeString("OverrideDate");
            $today  = date("Ymd");
            if ($stored !== $today && $stored !== "") {
                // Deaktivieren ohne Timer: Variablenwert zurücksetzen
                $this->SetValue("manual_override", false);
                $this->WriteAttributeString("OverrideDate", "");

                // Nach Deaktivierung sofort Plan beachten
                $planID = $this->ReadAttributeInteger("HeatingPlanID");
                if ($planID > 0 && IPS_EventExists($planID)) {
                    $this->executeCurrentPlanAction($planID);
                }
                $this->updateHeatingPhaseState($this->ReadAttributeInteger("HeatingPlanID"));

                // 2-Punkt-Regelung: nach Auto-Reset neu bewerten
                $controlType = (int)$this->ReadPropertyInteger('HeatingControlType');
                if ($controlType === 1) {
                    $this->updateTwoPointSwitch();
                }
                $this->LogMessage("Raumregelung: Override automatisch deaktiviert (Tageswechsel).", KL_MESSAGE);
            }
        }
    }

    private function applyOverride(): void
    {
        $this->SetValue("manual_override", true);
        $this->WriteAttributeString("OverrideDate", date("Ymd"));

        // Aktor sofort auf Heiz-Soll setzen
        $controlType = (int)$this->ReadPropertyInteger('HeatingControlType');
        if ($controlType !== 0) {
            $this->updateHeatingPhaseState($this->ReadAttributeInteger("HeatingPlanID"));
            return;
        }

        $actorID  = $this->ReadPropertyInteger("ID_Aktor");
        $heatVarID = $this->GetIDForIdent("set_heating_temperature");
        if ($actorID > 0 && IPS_VariableExists($actorID) && $heatVarID && IPS_VariableExists($heatVarID)) {
            $target = (float)GetValue($heatVarID);
            $this->writeAnalogActorTargetConsideringHeatingBlock((int)$actorID, (float)$target);
            $this->LogMessage("Raumregelung: Override aktiv: Aktor auf {$target}°C gesetzt.", KL_MESSAGE);
        }

        // Anzeige Phase aktualisieren
        $this->updateHeatingPhaseState($this->ReadAttributeInteger("HeatingPlanID"));
    }

    private function removeOverride(): void
    {
        $this->SetValue("manual_override", false);
        $this->WriteAttributeString("OverrideDate", "");

        $controlType = (int)$this->ReadPropertyInteger('HeatingControlType');

        // Wenn Phase aktuell Absenken ist und Heizung AN & Urlaub AUS,
        // dann sofort auf die Absenktemperatur zurückspringen
        $heatingVarID   = $this->ReadAttributeInteger("HeatingStatusVarID");
        $vacationVarID  = $this->ReadAttributeInteger("VacationStatusVarID");
        $heatingActive  = ($heatingVarID  > 0) ? GetValue($heatingVarID)  : true;
        $vacationActive = ($vacationVarID > 0) ? GetValue($vacationVarID) : false;

        if ($controlType === 0 && $heatingActive && !$vacationActive) {
            $action = $this->getCurrentPlanActionId(); // -1, 0, 1
            if ($action === 1) { // Absenken
                $actorID  = $this->ReadPropertyInteger("ID_Aktor");
                $lowVarID = $this->GetIDForIdent("set_lowering_temperature");
                if ($actorID > 0 && IPS_VariableExists($actorID) && $lowVarID && IPS_VariableExists($lowVarID)) {
                    $target = (float)GetValue($lowVarID);
                    $this->writeAnalogActorTargetConsideringHeatingBlock((int)$actorID, (float)$target);
                    $this->LogMessage("Raumregelung: Override AUS: Aktor auf Absenktemperatur {$target}°C gesetzt.", KL_MESSAGE);
                }
            }
        }

        // Nach Deaktivierung sofort Plan beachten
        $planID = $this->ReadAttributeInteger("HeatingPlanID");
        if ($planID > 0 && IPS_EventExists($planID)) {
            $this->executeCurrentPlanAction($planID);
        }
        $this->updateHeatingPhaseState($this->ReadAttributeInteger("HeatingPlanID"));
        $this->LogMessage("Raumregelung: Override deaktiviert.", KL_MESSAGE);
    }

    /**
     * Ermittelt basierend auf Heizungs-/Urlaubs-Status und dem Wochenplan die
     * aktuelle Heizphase und schreibt sie in die Variable "actual_heating_phase".
     */
    // Aktualisiert die Anzeigefase unter Beachtung von Heizung/Urlaub + Marker
    private function updateHeatingPhaseState(int $planID)
    {
        // Auto-Reset prüfen
        $this->ensureOverrideValidity();

        // Bei aktiver Übersteuerung / externem Erzwingen: Phase = Heizen (0)
        if ($this->isOverrideActive() || $this->isForceHeatingActive()) {
            $varID = @$this->GetIDForIdent("actual_heating_phase");
            if ($varID !== false) {
                $this->SetValue("actual_heating_phase", 0);
                $this->LogMessage("Raumregelung: actual_heating_phase → 0 (Override)", KL_MESSAGE);
            }
            return;
        }

        // Bei aktiver Heizsperre: Phase = Frostschutz (2)
        if ($this->isHeatingBlocked()) {
            $varID = @$this->GetIDForIdent("actual_heating_phase");
            if ($varID !== false) {
                $this->SetValue("actual_heating_phase", 2);
                $this->LogMessage("Raumregelung: actual_heating_phase → 2 (Heizstopp/Frostschutz)", KL_MESSAGE);
            }
            return;
        }
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
            $this->LogMessage("Raumregelung: actual_heating_phase → {$phase}", KL_MESSAGE);
        }
    }

    public function executeWindowOpenLowering()
    {
        $controlType = (int)$this->ReadPropertyInteger('HeatingControlType');
        if ($controlType !== 0) {
            $this->SetTimerInterval("WindowOpenTimer", 0);
            return;
        }

        $entries   = json_decode($this->ReadPropertyString('window_sensor'), true);
        $sensorIDs = is_array($entries) ? array_column($entries, 'InstanceID') : [];

        // Wenn noch ein Fenster geöffnet ist → Absenkung
        foreach ($sensorIDs as $id) {
            if (GetValue($id)) {
                $openTemp = $this->ReadPropertyFloat('windowdoor_temperature');
                $actorID  = $this->ReadPropertyInteger("ID_Aktor");
                if ($actorID > 0 && IPS_VariableExists($actorID)) {
                    $this->writeAnalogActorTargetConsideringHeatingBlock((int)$actorID, (float)$openTemp);
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
        $varID = @$this->GetIDForIdent("set_lowering_temperature");
        if ($varID === false) {
            return; // nicht angelegt
        }

        // Einblenden nur, wenn ein echter Wochenplan existiert
        $planID  = $this->ReadAttributeInteger("HeatingPlanID");
        $hasPlan = ($planID > 0) && IPS_EventExists($planID);

        IPS_SetHidden($varID, !$hasPlan); // kein Plan => ausblenden
    }
}
?>