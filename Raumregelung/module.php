<?php

// Definiert eine neue Klasse namens "Raumregelung", die von IPSModule erbt
class Aktor extends IPSModule
{
    // Diese Funktion wird aufgerufen, wenn das Modul erstellt wird
    public function Create()
    {
        // Diese Zeile muss immer vorhanden sein, damit das Modul richtig funktioniert
        // Eltern-Konstruktor aufrufen, damit das Modul korrekt initialisiert wird
        parent::Create();


        ############################## Erstellen von Variablen im Objektbaum + Zuweisung einer Darstellung
        // Float-Variable für die Soll-Heiztemperatur mit Slider-Darstellung
        $this->RegisterVariableFloat("set_heating_temperature", $this->Translate("Heating temperature"),['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'TEMPLATE' => VARIABLE_TEMPLATE_SLIDER_ROOM_TEMPERATURE, 'USAGE_TYPE' => 0, 'MIN' => 15, 'MAX' => 25],1);
        $this->RegisterVariableFloat("set_lowering_temperature", $this->Translate("Lowering temperature"),['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'TEMPLATE' => VARIABLE_TEMPLATE_SLIDER_ROOM_TEMPERATURE, 'USAGE_TYPE' => 0, 'MIN' => 0, 'MAX' => 5],2);
        //$this->RegisterVariableInteger("actual_heating_phase", $this->Translate("Heating phase"),['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'TEMPLATE' => VARIABLE_TEMPLATE_SLIDER_ROOM_TEMPERATURE, 'USAGE_TYPE' => 0, 'MIN' => 0, 'MAX' => 5],4);

        ############################## Icon Zuweisung
        //$iconID = $this->GetIDForIdent("set_heating_temperature");
        //IPS_SetIcon($iconID, 'temperature-arrow-down');

        $iconID = $this->GetIDForIdent("set_lowering_temperature");
        IPS_SetIcon($iconID, 'temperature-arrow-down');

        
        ############################## Aktivieren der Variablenaktion (Standardaktion)
        // Erlaubt, die erstellen Variablen verändert werden düfen - Aktivieren der Standardaktion
        $this->EnableAction("set_heating_temperature");
        $this->EnableAction("set_lowering_temperature");
        //$this->EnableAction("actual_heating_phase");
        
        ############################## Erstellen von Eigenschaften, Konfigurationsformular (form.json)
        // ID der verbundenen Aktor-Variable
        $this->RegisterPropertyInteger("ID_Aktor", 0);
        // ID der Variable, aus der die Ist-Temperatur verlinkt werden soll
        $this->RegisterPropertyInteger("Is_Temperature", 0);
        // Wochenplanauswahl
        $this->RegisterPropertyInteger("Weekly_Schedule_Selection", 0);


        ############################## Erstellen von Attributen
        // Speichert die vorherige Temperatur zur Historien- oder Änderungserkennung
        $this->RegisterAttributeInteger("Attribute_OldTemperature", 0);
    }



    // Diese Funktion wird aufgerufen, wenn das Modul gelöscht wird
    // Hier könnten Aufräumarbeiten stehen, was ausgeführt werden soll, die Instanz des Moduls gelöscht wird
    public function Destroy()
    {
        // Diese Zeile muss immer vorhanden sein
        // Eltern-Destruktor aufrufen
        parent::Destroy();
    }



    // Diese Funktion wird aufgerufen, wenn sich etwas am Modul ändert
    public function ApplyChanges()
    {
        // Diese Zeile muss immer vorhanden sein
        // Eltern-ApplyChanges aufrufen
        parent::ApplyChanges();
    
        ############################## Linkhandling (Erstellung, Update, Profilzuweisung und Löschung)
        // Bestehenden Link unter dem Ident "Link_Ist_Temperatur" ermitteln
        $linkID = @$this->GetIDForIdent("Link_Ist_Temperatur");
        // Ausgewählte Ziel-Variable aus den Eigenschaften
        $targetID = $this->ReadPropertyInteger("Is_Temperature");
        // Wochenplanauswahl
        $weeklyscheduleselection = $this->ReadPropertyInteger("Weekly_Schedule_Selection");

        // Übergabe der Wochenplanauswahl an die Funktion
        $this->weeklyschedule($weeklyscheduleselection);

        
        // Wenn ein Link existiert, aber keine gültige Ziel-ID mehr ausgewählt ist, dann löschen
        if ($linkID !== false 
            && (!IPS_VariableExists($targetID) || $targetID === 0)
        ) {
            IPS_DeleteLink($linkID);               // Link-Objekt entfernen
            $linkID = false;                       // Markiere, dass kein Link mehr existiert
        }
    
        // Wenn kein Link existiert und jetzt eine gültige Ziel-Variable ausgewählt ist, dann neu anlegen
        if ($linkID === false && IPS_VariableExists($targetID) && $targetID > 0
        ) {
            $ist_temperatur = IPS_CreateLink();                     // Link-Objekt erstellen
            IPS_SetName($ist_temperatur, "Ist-Temperatur");         // Link benennen
            IPS_SetParent($ist_temperatur, $this->InstanceID);      // Unter diesem Modul ablegen
            IPS_SetLinkTargetID($ist_temperatur, $targetID);        // Ziel-Variable setzen
            IPS_SetIdent($ist_temperatur, "Link_Ist_Temperatur");  // Ident vergeben
            $linkID = $ist_temperatur;                              // neue Link-ID merken
            IPS_SetPosition($linkID, 0);
        }
    
        // Wenn Link und Ziel existieren, Ziel aktualisieren
        if ($linkID !== false && IPS_VariableExists($targetID) && $targetID > 0) {
            IPS_SetLinkTargetID($linkID, $targetID);
        }
    
        // 4. Profilzuweisung: Falls eine gültige Ziel-ID vorhanden ist, Temperatur-Profil zuweisen
        if (IPS_VariableExists($targetID) && $targetID > 0) {
            IPS_SetVariableCustomProfile($targetID, "~Temperature");
        }
    }
    


    ############################## Abfangen von Benutzer-Eingaben in den Variablen. Diese Funktion wird aufgerufen, wenn der Benutzer eine Aktion im Web-Interface durchführt
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'set_heating_temperature':
                // ID des Ziel-Aktors aus der Instanzkonfiguration auslesen
                $actorID1    = $this->ReadPropertyInteger('ID_Aktor');

                // Wenn eine gültige Aktor-Variable existiert, Wert setzen und weiterleiten
                if ($actorID1 > 0 && IPS_VariableExists($actorID1)) {
                    $this->SetValue("set_heating_temperature", $Value);
                    // Aktion zum Aktor weiterreichen   
                    RequestAction($actorID1, $Value);
                    break;
                }

            case 'set_lowering_temperature':
                // ID des Ziel-Aktors aus der Instanzkonfiguration auslesen
                $actorID2    = $this->ReadPropertyInteger('ID_Aktor');


                $this->SetValue("set_lowering_temperature", $Value);
                RequestAction($actorID2, $Value);
                    break;
        }
    }



    public function weeklyschedule($Value)
    {
        //Auslesen von Variablen IDs, die bei jeder Auswahl gebraucht werden
        // Variable-ID für set_heating_temperature ermitteln
        $tempVarID1 = $this->GetIDForIdent("set_heating_temperature");

        // Variable-ID für set_lowering_temperature ermitteln
        $tempVarID2 = $this->GetIDForIdent("set_lowering_temperature");
    
        // Aktor-ID aus der Instanz-Konfiguration auslesen
        $actorID   = $this->ReadPropertyInteger("ID_Aktor");

        switch ($Value) {
            case 0:
                // Aufruf der Funktion zum löschen eines Wochenplans
                $this->DeleteWeeklySchedule();
                IPS_LogMessage('Raumregelung', 'Der Wochenplan / Heizplan wurde gelöscht.');
                break;

            case 1:
                // Aufruf der Funktion zum löschen eines Wochenplans
                $this->DeleteWeeklySchedule();

                $existing = @$this->GetIDForIdent('HeatingPlan');
                if ($existing === false) {
                    // noch kein Plan → neu anlegen
                    $heatingPlan = IPS_CreateEvent(2);
                    IPS_SetParent($heatingPlan, $this->InstanceID);
                    IPS_SetIdent($heatingPlan, 'HeatingPlan');
                    IPS_SetName($heatingPlan, 'Heizplan');
                    IPS_SetEventActive($heatingPlan, true);
                    IPS_SetPosition($heatingPlan, 4);
                    IPS_SetIcon($heatingPlan, "calendar-clock");
                    
                    // Aufruf der Funktion zum Prüfen der aktuellen Aktion im Wochenplan
                    //$currentAction = GetActiveAction($heatingPlan);

                    IPS_SetEventScheduleAction($heatingPlan, 0, "Heizen", 0xFF0000, "SetValue($actorID, GetValue($tempVarID1));");
                    IPS_SetEventScheduleAction($heatingPlan, 1, "Absenken", 0xFF7F00, "SetValue($actorID, GetValue($tempVarID1)-GetValue($tempVarID2));");
                   
                    IPS_SetEventScheduleGroup($heatingPlan, 0, 127);
                
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 0, 0, 0, 0, 0);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 1, 6, 0, 0, 1);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 2, 20, 0, 0, 0);

                    IPS_LogMessage('Raumregelung', 'Der Wochenplan / Heizplan wurde angelegt / geändert.');
                }
                else {
                    // Plan existiert bereits
                    IPS_LogMessage('Raumregelung', 'Heizplan existiert bereits (ID '.$existing.')');
                }
                break;

            case 2:
                // Aufruf der Funktion zum löschen eines Wochenplans
                $this->DeleteWeeklySchedule();

                $existing = @$this->GetIDForIdent('HeatingPlan');
                if ($existing === false) {
                    // noch kein Plan → neu anlegen
                    $heatingPlan = IPS_CreateEvent(2);
                    IPS_SetParent($heatingPlan, $this->InstanceID);
                    IPS_SetIdent($heatingPlan, 'HeatingPlan');
                    IPS_SetName($heatingPlan, 'Heizplan');
                    IPS_SetEventActive($heatingPlan, true);
                    IPS_SetPosition($heatingPlan, 5);
                    IPS_SetIcon($heatingPlan, "calendar-clock");

                    IPS_SetEventScheduleAction($heatingPlan, 0, "Heizen", 0xFF0000, "SetValue($actorID, GetValue($tempVarID1));");
                    IPS_SetEventScheduleAction($heatingPlan, 1, "Absenken", 0xFF7F00, "SetValue($actorID, GetValue($tempVarID1)-GetValue($tempVarID2));");
                    
                    IPS_SetEventScheduleAction($heatingPlan, 0, "Heizen", 0xFF0000, "");
                    IPS_SetEventScheduleAction($heatingPlan, 1, "Absenken",   0xFF7F00, "");

                    IPS_SetEventScheduleGroup($heatingPlan, 0, 31);
                    IPS_SetEventScheduleGroup($heatingPlan, 1, 96);

                    IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 0, 0, 0, 0, 0);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 1, 6, 0, 0, 1);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 2, 20, 0, 0, 0);

                    IPS_SetEventScheduleGroupPoint($heatingPlan, 1, 0, 0, 0, 0, 0);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 1, 1, 8, 0, 0, 1);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 1, 2, 22, 0, 0, 0);

                    IPS_LogMessage('Raumregelung', 'Heizplan wurde angelegt (ID '.$heatingPlan.')');
                }
                else {
                    // Plan existiert bereits
                    IPS_LogMessage('Raumregelung', 'Heizplan existiert bereits (ID '.$existing.')');
                }
                break;
            case 3:
                // Aufruf der Funktion zum löschen eines Wochenplans
                $this->DeleteWeeklySchedule();

                $existing = @$this->GetIDForIdent('HeatingPlan');
                if ($existing === false) {
                    // noch kein Plan → neu anlegen
                    $heatingPlan = IPS_CreateEvent(2);
                    IPS_SetParent($heatingPlan, $this->InstanceID);
                    IPS_SetIdent($heatingPlan, 'HeatingPlan');
                    IPS_SetName($heatingPlan, 'Heizplan');
                    IPS_SetEventActive($heatingPlan, true);
                    IPS_SetPosition($heatingPlan, 5);
                    IPS_SetIcon($heatingPlan, "calendar-clock");

                    IPS_SetEventScheduleAction($heatingPlan, 0, "Heizen", 0xFF0000, "SetValue($actorID, GetValue($tempVarID1));");
                    IPS_SetEventScheduleAction($heatingPlan, 1, "Absenken", 0xFF7F00, "SetValue($actorID, GetValue($tempVarID1)-GetValue($tempVarID2));");
                    
                    IPS_SetEventScheduleAction($heatingPlan, 0, "Heizen", 0xFF0000, "");
                    IPS_SetEventScheduleAction($heatingPlan, 1, "Absenken",   0xFF7F00, "");

                    IPS_SetEventScheduleGroup($heatingPlan, 0, 1);
                    IPS_SetEventScheduleGroup($heatingPlan, 1, 2);
                    IPS_SetEventScheduleGroup($heatingPlan, 2, 4);
                    IPS_SetEventScheduleGroup($heatingPlan, 3, 8);
                    IPS_SetEventScheduleGroup($heatingPlan, 4, 16);
                    IPS_SetEventScheduleGroup($heatingPlan, 5, 32);
                    IPS_SetEventScheduleGroup($heatingPlan, 6, 64);

                    IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 0, 0, 0, 0, 0);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 1, 6, 0, 0, 1);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 0, 2, 20, 0, 0, 0);

                    IPS_SetEventScheduleGroupPoint($heatingPlan, 1, 0, 0, 0, 0, 0);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 1, 1, 6, 0, 0, 1);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 1, 2, 20, 0, 0, 0);

                    IPS_SetEventScheduleGroupPoint($heatingPlan, 2, 0, 0, 0, 0, 0);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 2, 1, 6, 0, 0, 1);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 2, 2, 20, 0, 0, 0);

                    IPS_SetEventScheduleGroupPoint($heatingPlan, 3, 0, 0, 0, 0, 0);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 3, 1, 6, 0, 0, 1);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 3, 2, 20, 0, 0, 0);

                    IPS_SetEventScheduleGroupPoint($heatingPlan, 4, 0, 0, 0, 0, 0);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 4, 1, 6, 0, 0, 1);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 4, 2, 20, 0, 0, 0);

                    IPS_SetEventScheduleGroupPoint($heatingPlan, 5, 0, 0, 0, 0, 0);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 5, 1, 8, 0, 0, 1);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 5, 2, 20, 0, 0, 0);

                    IPS_SetEventScheduleGroupPoint($heatingPlan, 6, 0, 0, 0, 0, 0);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 6, 1, 8, 0, 0, 1);
                    IPS_SetEventScheduleGroupPoint($heatingPlan, 6, 2, 20, 0, 0, 0);

                    IPS_LogMessage('Raumregelung', 'Heizplan wurde angelegt (ID '.$heatingPlan.')');
                }
                else {
                    // Plan existiert bereits
                    IPS_LogMessage('Raumregelung', 'Heizplan existiert bereits (ID '.$existing.')');
                }             
        }
    }

    public function DeleteWeeklySchedule()
    {
        $existing = @$this->GetIDForIdent('HeatingPlan');
        if ($existing !== false) {
            IPS_DeleteEvent($existing);
            IPS_LogMessage('Raumregelung', 'Heizplan wurde per Button gelöscht (ID '.$existing.')');
            
            // Die Auswahl zurücksetzen auf "Kein Wochenplan"
            $this->UpdateFormField('Weekly_Schedule_Selection', 'value', 0);
        }
    }

    
    private function GetActiveAction($wochenplanID)
    {
        $e = IPS_GetEvent($wochenplanID);
        $actionID = false;
        //Durch alle Gruppen gehen
        foreach($e['ScheduleGroups'] as $g) {
            //Überprüfen ob die Gruppe für heute zuständig ist
            if($g['Days'] & (2 ** (date('N') - 1)) > 0) {
                //Aktuellen Schaltpunkt suchen. Wir nutzen die Eigenschaft, dass die Schaltpunkte immer aufsteigend sortiert sind.
                foreach($g['Points'] as $p) {
                if(date("H") * 3600 + date("i") * 60 + date("s") >= $p['Start']['Hour'] * 3600 + $p['Start']['Minute'] * 60 + $p['Start']['Second']) {
                    $actionID = $p['ActionID'];
                } else {
                    break; //Sobald wir drüber sind, können wir abbrechen.
                }
            }
                break; //Sobald wir unseren Tag gefunden haben, können wir die Schleife abbrechen. Jeder Tag darf nur in genau einer Gruppe sein.
            }
        }

        $action = false;
        // Durch alle Aktionen gehen
        foreach($e['ScheduleActions'] as $a) {
            if ($a["ID"] === $actionID) {
                $action = $a;
            }
        }

        return($actionID);
        //var_dump($action);
    }
}