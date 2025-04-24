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
        $this->RegisterVariableFloat("set_heating_temperature", $this->Translate("Heating temperature"), ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'TEMPLATE' => VARIABLE_TEMPLATE_SLIDER_ROOM_TEMPERATURE, 'USAGE_TYPE' => 0], 2);
        

        ############################## Aktivieren der Variablenaktion (Standardaktion)
        // Erlaubt, dass das Modul auf Änderungen an "set_heating_temperature" reagiert
        $this->EnableAction("set_heating_temperature");

        
        ############################## Erstellen von Eigenschaften, Konfigurationsformular (form.json)
        // ID der verbundenen Aktor-Variable
        $this->RegisterPropertyInteger("ID-Aktor", 0);
        // ID der Variable, aus der die Ist-Temperatur verlinkt werden soll
        $this->RegisterPropertyInteger("Is-Temperature", 0);


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
        // Bestehenden Link unter dem Ident "LinkLuftfeuchtigkeit" ermitteln
        $linkID = @$this->GetIDForIdent("LinkLuftfeuchtigkeit");
        // Ausgewählte Ziel-Variable aus den Eigenschaften
        $targetID = $this->ReadPropertyInteger("Is-Temperature");
        
        // Wenn ein Link existiert, aber keine gültige Ziel-ID mehr ausgewählt ist, dann löschen
        if ($linkID !== false 
            && (!IPS_VariableExists($targetID) || $targetID === 0)
        ) {
            IPS_DeleteLink($linkID);               // Link-Objekt entfernen
            $linkID = false;                       // Markiere, dass kein Link mehr existiert
        }
    
        // Wenn kein Link existiert und jetzt eine gültige Ziel-Variable ausgewählt ist, dann neu anlegen
        if ($linkID === false 
            && IPS_VariableExists($targetID) 
            && $targetID > 0
        ) {
            $ist_temperatur = IPS_CreateLink();                     // Link-Objekt erstellen
            IPS_SetName($ist_temperatur, "Ist-Temperatur");         // Link benennen
            IPS_SetParent($ist_temperatur, $this->InstanceID);      // Unter diesem Modul ablegen
            IPS_SetLinkTargetID($ist_temperatur, $targetID);        // Ziel-Variable setzen
            IPS_SetIdent($ist_temperatur, "LinkLuftfeuchtigkeit");  // Ident vergeben
            $linkID = $ist_temperatur;                              // neue Link-ID merken
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
                // Setzt die Heiztemperatur auf den neuen Wert
                // ID des Ziel-Aktors aus der Instanzkonfiguration auslesen
                $actorID    = $this->ReadPropertyInteger('ID-Aktor');

                // Wenn eine gültige Aktor-Variable existiert, Wert setzen und weiterleiten
                if ($actorID > 0 && IPS_VariableExists($actorID)) {
                    $this->SetValue("set_heating_temperature", $Value);
                    // Aktion zum Aktor weiterreichen   
                    RequestAction($actorID, $Value);
                    break;
                }

        }
    }
}